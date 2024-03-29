<?php

namespace Hn\ShareASecret\Controller;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Exception;
use Hn\ShareASecret\Exceptions\SecretNotFoundException;
use Hn\ShareASecret\Service\EventLogService;
use Hn\ShareASecret\Service\SecretService;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidArgumentValueException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException;

/**
 * Class SecretController
 */
class SecretController extends ActionController
{
    /**
     * @var SecretService
     */
    private $secretService;

    /**
     * @var EventLogService
     */
    private $eventLogService;

    /**
     * SecretController constructor.
     * @param \Hn\ShareASecret\Service\SecretService $secretService
     * @param EventLogService $eventLogService
     */
    public function __construct(
        \Hn\ShareASecret\Service\SecretService $secretService,
        EventLogService $eventLogService
    )
    {
        $this->secretService = $secretService;
        $this->eventLogService = $eventLogService;
    }

    /**
     * @param array $isInvalid
     * @param string $message
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException
     */
    public function newAction(array $isInvalid = [], string $message = '')
    {
        $userPassword = $this->secretService->generateUserPassword();
        $this->view->assign('isInvalid', $isInvalid);
        $this->view->assign('message', $message);
        $this->view->assign('messageLength', strlen($message));
        $this->view->assign('userPassword', $userPassword);
        $this->view->assign('messageMaxLength', $this->secretService->getMessageMaxLength());
    }

    /**
     * @param string $message
     * @param string $userPassword
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     * @throws Exception
     */
    public function createAction(string $message, string $userPassword)
    {
        try {
            $linkHash = $this->secretService->createSecret($message, $userPassword);
            $this->redirect('showLink', null, null, [
                'linkHash' => $linkHash,
                'userPassword' => $userPassword
            ]);
        } catch (InvalidArgumentValueException $e) {
            $this->forward('new', null, null, [
                'isInvalid' => [
                    'message' => 1,
                ],
                'message' => $message,
            ]);
        }
    }

    /**
     * @param string $linkHash
     * @param string $userPassword
     */
    public function showLinkAction(string $linkHash, string $userPassword)
    {
        $this->view->assign('linkHash', $linkHash);
        $this->view->assign('userPassword', $userPassword);
    }

    /**
     * @param string $linkHash
     * @param bool $isInvalid
     */
    public function inputPasswordAction(string $linkHash, bool $isInvalid = false)
    {
        $this->eventLogService->logRequest();
        $this->view->assign('linkHash', $linkHash);
        $this->view->assign('isInvalid', $isInvalid);
    }

    /**
     * @param string $linkHash
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     * @throws Exception
     */
    public function redirectToInputPassword(string $linkHash): void
    {
        sleep(3 + random_int(0, 2));
        $this->redirect('inputPassword', null, null, [
            'linkHash' => $linkHash,
            'isInvalid' => true,
        ]);
    }

    /**
     * @param string $linkHash
     * @param string $userPassword
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     * @throws EnvironmentIsBrokenException
     */
    public function showAction(string $linkHash, string $userPassword)
    {
        try {
            $message = $this->secretService->getDecryptedMessage($userPassword, $linkHash);
            $this->view->assign('message', $message);
            $this->view->assign('indexHash', $this->secretService->getIndexHash($userPassword, $linkHash));
        } catch (SecretNotFoundException | WrongKeyOrModifiedCiphertextException $e){
            $this->eventLogService->logNotFound();
            $this->redirectToInputPassword($linkHash);
        }
    }

    public function pleaseLoginAction() {}

    /**
     * @param string $indexHash
     */
    public function deleteMessageAction(string $indexHash)
    {
        try {
            $this->secretService->deleteSecretByIndexHash($indexHash);
        } catch (SecretNotFoundException $e){
            $this->eventLogService->logNotFound();
        }
    }
}