<?php

namespace Hn\ShareASecret\Domain\Repository;

use Hn\ShareASecret\Domain\Model\Secret;
use Hn\ShareASecret\Exceptions\SecretNotFoundException;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Class SecretRepository
 * @package Hn\ShareASecret\Domain\Repository
 */
class SecretRepository extends Repository
{
    public function save()
    {
        $this->persistenceManager->persistAll();
    }

    /**
     * @param string $hash
     * @return Secret
     * @throws SecretNotFoundException
     */
    public function findOneByIndexHash(string $hash): ?Secret
    {
        $query = $this->createQuery();
        $query->matching($query->equals('indexHash', $hash));
        $secret = $query->execute()->getFirst();
        if(!$secret){
            throw new SecretNotFoundException();
        }
        return $secret;
    }

    public function deleteSecret(Secret $secret)
    {
        $this->remove($secret);
        $this->save();
    }




}