<?php

namespace AppBundle\Repository;

/**
 * EpisodeRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class EpisodeRepository extends \Doctrine\ORM\EntityRepository
{
    public function getNextEpisodes()
    {
        $em = $this->getEntityManager();

        $query = $em->createQuery(
            'SELECT e FROM AppBundle:Episode e WHERE e.date > :today ORDER BY e.date ASC')
            ->setParameter('today', new \DateTime());

        return $query->getResult();
    }
}
