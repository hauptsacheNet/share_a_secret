<?php

namespace Hn\ShareASecret\Service;

use DateTime;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Exception;
use Hn\ShareASecret\Domain\Model\EventLog;
use Hn\ShareASecret\Domain\Model\Secret;
use Hn\ShareASecret\Domain\Repository\SecretRepository;
use Hn\ShareASecret\Exceptions\SecretNotFoundException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidArgumentValueException;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;

class SecretService
{
    public $messageMaxLength;

    /**
     * @var SecretRepository;
     */
    private $secretRepository;
    /** @var EventLogService */
    private $eventLogService;
    private $typo3Key;
    private $userPasswordCharacterClasses = [
        // The letters I, l and O, 0 are removed since they are hard to distinguish on some fonts.
        'letters' => [
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M',
            'N', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'm',
            'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
        ],

        'digits' => [
            '2', '3', '4', '5', '6', '7', '8', '9',
        ],

        // '{' and '}' are being used to delimit the regular
        // expression in function userPasswordIsValid()
        'specialCharacters' => [
            '!', '#', '$', '%', '&', '(', ')', '*', '+', ',',
            '-', '.', '/', ':', ';', '=', '?', '@', '\\', '_', '~',
        ],
    ];

    private $userPasswordCharacters;
    private $userPasswordLength;
    private $containsSpecialCharacters;

    /**
     * SecretService constructor.
     * @param \Hn\ShareASecret\Domain\Repository\SecretRepository $secretRepository
     * @param EventLogService $eventLogService
     * @throws \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException
     * @throws \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException
     */
    public function __construct(
        \Hn\ShareASecret\Domain\Repository\SecretRepository $secretRepository,
        EventLogService $eventLogService
    )
    {
        $this->typo3Key = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'];
        $this->secretRepository = $secretRepository;
        $this->eventLogService = $eventLogService;
        $this->userPasswordCharacters = array_merge(
            $this->userPasswordCharacterClasses['letters'],
            $this->userPasswordCharacterClasses['digits']
        );
        $this->initBackendSettings();
    }

    /**
     * @param string $message
     * @param string $userPassword
     * @return string, a link hash for the secret
     * @throws EnvironmentIsBrokenException
     * @throws InvalidArgumentValueException
     * @throws IllegalObjectTypeException
     * @throws Exception
     */
    public function createSecret(string $message, string $userPassword): string
    {
        $message = trim($message);
        $userPassword = trim($userPassword);

        if (!($message && $userPassword) || !$this->isValidMessage($message)) {
            throw new InvalidArgumentValueException();
        }

        $linkHash = $this->generateLinkHash();
        $password = $this->createPassword($userPassword, $linkHash);
        $indexHash = $this->createIndexHash($userPassword, $linkHash);
        $encMessage = $this->encryptMessage($message, $password);
        $secret = new Secret($encMessage, $indexHash);
        $this->secretRepository->add($secret);
        $this->secretRepository->save();

        $this->eventLogService->logCreate($secret);
        return $linkHash;
    }

    /**
     * @param string $userPassword
     * @param $linkHash
     * @return Secret
     * @throws SecretNotFoundException
     */
    public function getSecret(string $userPassword, $linkHash): Secret
    {
        $indexHash = $this->createIndexHash($userPassword, $linkHash);
        $secret = $this->secretRepository->findOneByIndexHash($indexHash);
        if (!$secret) {
            throw new SecretNotFoundException();
        }
        return $secret;
    }

    /**
     * @param Secret $secret
     * @param string $userPassword
     * @param string $linkHash
     * @return string
     * @throws EnvironmentIsBrokenException
     * @throws WrongKeyOrModifiedCiphertextException
     * @throws SecretNotFoundException
     */
    public function getDecryptedMessage(string $userPassword, string $linkHash): string
    {
        $secret = $this->getSecret($userPassword, $linkHash);
        $password = $this->createPassword($userPassword, $linkHash);
        $decryptedMessage = Crypto::decryptWithPassword($secret->getMessage(), $password);

        $this->eventLogService->logSuccess($secret);

        return $decryptedMessage;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function generateUserPassword()
    {
        $maxIndex = count($this->userPasswordCharacters) - 1;
        $userPassword = '';
        while (!$this->userPasswordIsValid($userPassword)) {
            $userPassword = ''; // Reset non-valid password
            for ($i = 0; $i < $this->userPasswordLength; $i++) {
                $userPassword .= $this->userPasswordCharacters[random_int(0, $maxIndex)];
            }
        }
        return $userPassword;
    }

    public function userPasswordIsValid(string $userPassword)
    {
        $specialChars = implode($this->userPasswordCharacterClasses['specialCharacters']);
        $isValid = false;
        if (
            preg_match('/[A-Z]/', $userPassword) &&
            preg_match('/[a-z]/', $userPassword) &&
            preg_match('/[0-9]/', $userPassword)
        ) {
            if ($this->containsSpecialCharacters){
                if (preg_match("{[$specialChars]}", $userPassword)){
                    $isValid = true;
                } else{
                    $isValid = false;
                }
            } else {
                $isValid = true;
            }
        }
        return $isValid;
    }

    /**
     * @param string $userPassword
     * @param string $linkHash
     * @throws SecretNotFoundException
     */
    public function deleteSecret(string $userPassword, string $linkHash)
    {
        $secret = $this->getSecret($userPassword, $linkHash);
        $this->secretRepository->deleteSecret($secret);
    }

    /**
     * @param string $indexHash
     * @throws IllegalObjectTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException
     * @throws SecretNotFoundException
     */
    public function deleteSecretByIndexHash(string $indexHash)
    {
        $secret = $this->secretRepository->findOneByIndexHash($indexHash);
        if($secret){
            $this->secretRepository->deleteSecret($secret);
            $this->eventLogService->logDelete($secret);
        }
    }

    /**
     * @param string $userPassword
     * @param string $linkHash
     * @return string
     * @throws SecretNotFoundException
     */
    public function getIndexHash(string $userPassword, string $linkHash)
    {
        return $this->getSecret($userPassword, $linkHash)->getIndexHash();
    }

    /**
     * @return mixed
     */
    public function getMessageMaxLength()
    {
        return $this->messageMaxLength;
    }

    /**
     * @param string $userPassword
     * @param string $linkHash
     * @return string
     */
    private function createPassword(string $userPassword, string $linkHash): string
    {
        return $userPassword . $this->typo3Key . $linkHash;
    }

    /**
     * @param string $userPassword
     * @param string $linkHash
     * @return string
     */
    private function createIndexHash(string $userPassword, string $linkHash)
    {
        $password = $this->createPassword($userPassword, $linkHash);
        return hash('sha512', $password);
    }

    /**
     * @return string
     * @throws Exception
     */
    private function generateLinkHash(): string
    {
        $string = strval((new DateTime())->getTimestamp() * 1.0 / random_int(1, PHP_INT_MAX));
        return hash('sha512', $string);
    }

    /**
     * @param string $message
     * @param string $plainPassword
     * @return string
     * @throws EnvironmentIsBrokenException
     */
    private function encryptMessage(string $message, string $plainPassword)
    {
        $encryptedMessage = Crypto::encryptWithPassword($message, $plainPassword);
        return $encryptedMessage;
    }

    private function initBackendSettings()
    {
        if(class_exists(ExtensionConfiguration::class)) {

            $backendSettings = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('share_a_secret');
        } else {

            $backendSettings = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['share_a_secret']);
        }

        $this->userPasswordLength = $backendSettings['userPasswordLength'] ?? 8;
        if($this->userPasswordLength > 100){
            $this->userPasswordLength = 100;
        }

        $this->containsSpecialCharacters = $backendSettings['containsSpecialCharacters'];
        if($this->containsSpecialCharacters){
            $this->userPasswordCharacters = array_merge(
                $this->userPasswordCharacters,
                $this->userPasswordCharacterClasses['specialCharacters']
            );
            if($this->userPasswordLength < 4){
                // For the password validation to succeed,
                // the password must have at least 4 characters.
                $this->userPasswordLength = 4;
            }
        } else {
            if($this->userPasswordLength < 3 ){
                // For the password validation to succeed,
                // the password must have at least 3 characters.
                $this->userPasswordLength = 3;
            }
        }

        $this->messageMaxLength = $backendSettings['messageMaxLength'] ?? 1000;
    }

    private function isValidMessage(string $message)
    {
        $message = trim($message);

        if($message === ''){
            return false;
        }

        return strlen($message) <= $this->messageMaxLength;
    }
}
