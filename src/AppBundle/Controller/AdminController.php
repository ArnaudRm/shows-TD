<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Forms\ShowType;
use AppBundle\Forms\EpisodeType;
use AppBundle\Entity\TVShow;
use AppBundle\Entity\Season;
use AppBundle\Entity\Episode;
use aharen\OMDbAPI;

/**
 * @Route("/admin")
 */
class AdminController extends Controller
{
    /**
     * @Route("/addShow", name="admin_add_show")
     * @Template()
     */
    public function addShowAction(Request $request)
    {
        $show = new TVShow;
        $form = $this->createForm(ShowType::class, $show);
        $success = false;

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $show->getImage();
            if ($file) {
                // Handling file upload
                $filename = md5(uniqid()) . '.' . $file->guessExtension();
                $webRoot = $this->get('kernel')->getRootDir() . '/../web';

                $file->move($webRoot . '/uploads', $filename);
                $show->setImage($filename);
            }

            $em = $this->get('doctrine')->getManager();
            $em->persist($show);
            $em->flush();
            $success = true;
        }

        return [
            'form' => $form->createView(),
            'success' => $success
        ];
    }

    /**
     * @Route("/addSeason/{id}", name="admin_add_season")
     */
    public function addSeasonAction($id)
    {
        $em = $this->get('doctrine')->getManager();
        $repo = $em->getRepository('AppBundle:TVShow');

        if ($show = $repo->find($id)) {
            $season = new Season;
            $season
                ->setShow($show)
                ->setNumber(count($show->getSeasons()) + 1);
            $em->persist($season);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('show', ['id' => $id]));
    }

    /**
     * @Route("/deleteSeason/{id}", name="admin_delete_season")
     */
    public function deleteSeasonAction($id)
    {
        $em = $this->get('doctrine')->getManager();
        $repo = $em->getRepository('AppBundle:Season');
        if ($season = $repo->find($id)) {
            $id = $season->getShow()->getId();
            $em->remove($season);
            $em->flush();
            return $this->redirect($this->generateUrl('show', ['id' => $id]));
        } else {
            return $this->redirect($this->generateUrl('homepage'));
        }
    }

    /**
     * @Route("/deleteEpisode/{id}", name="admin_delete_episode")
     */
    public function deleteEpisodeAction($id)
    {
        $em = $this->get('doctrine')->getManager();
        $repo = $em->getRepository('AppBundle:Episode');
        if ($episode = $repo->find($id)) {
            $id = $episode->getSeason()->getShow()->getId();
            $em->remove($episode);
            $em->flush();
            return $this->redirect($this->generateUrl('show', ['id' => $id]));
        } else {
            return $this->redirect($this->generateUrl('homepage'));
        }
    }

    /**
     * @Route("/addEpisode/{id}", name="admin_add_episode")
     * @Template()
     */
    public function addEpisodeAction($id, Request $request)
    {
        $em = $this->get('doctrine')->getManager();
        $repo = $em->getRepository('AppBundle:Season');

        if ($season = $repo->find($id)) {
            $episode = new Episode;
            $episode
                ->setSeason($season)
                ->setNumber(count($season->getEpisodes()) + 1);

            $form = $this->createForm(EpisodeType::class, $episode);

            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $em->persist($episode);
                $em->flush();
                return $this->redirect($this->generateUrl('show', [
                    'id' => $episode->getSeason()->getShow()->getId()
                ]));
            }
        } else {
            return $this->redirect($this->generateUrl('homepage'));
        }

        return [
            'form' => $form->createView()
        ];
    }

    /**
     * @Route("/omdb", name="admin_omdb")
     * @Template()
     */
    public function omdbAction(Request $request)
    {
        $form = $this->createFormBuilder()
            ->add('keyword')
            ->getForm();

        $result = [];
        $error = null;

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $omdb = new OMDbAPI('7d17d2ef');
            $result = $omdb->search($data['keyword'], 'SERIES');

            if (!property_exists($result->data, 'Error')) {
                $result = $result->data->Search;
            } else {
                //TODO:implement flashErrorMessage
                $error = $result->data->Error;
            }
        }

        return [
            'form' => $form->createView(),
            'result' => $result,
            'error' => $error,
        ];
    }

    /**
     * @Route("/importShow/{id}", name="admin_import_show")
     */
    public function importShowFromOmdb($id)
    {

        $em = $this->get('doctrine')->getManager();
        $repo = $em->getRepository('AppBundle:TVShow');

        $omdb = new OMDbAPI('7d17d2ef');
        $result = $omdb->fetch('i', $id)->data;
        if ($result->Type === 'series') {
            //Add show informations
            $show = new TVShow;
            $show
                ->setName($result->Title)
                ->setSynopsis($result->Plot);

            $file = $result->Poster;
            if ($file) {
                $webRoot = $this->get('kernel')->getRootDir() . '/../web';
                $extension = pathinfo($file)['extension'];
                $filename = $id . '.' . $extension;
                copy($file, $webRoot . '/uploads/' . $filename);
                $show->setImage($filename);
            }
            $em->persist($show);

            //TODO:Add show's seasons
            for ($i = 1; $i <= $result->totalSeasons; $i++) {
                $seasonOmdb = $omdb->fetch('i', $id, ['Season' => $i]);
                $seasonData = $seasonOmdb->data;

                $season = new Season();
                $season
                    ->setShow($show)
                    ->setNumber($i);
                $em->persist($season);

                if (!property_exists($seasonData, 'Error'))
                    //TODO:Add each season's episode
                    foreach ($seasonData->Episodes as $episodeData) {

                        if(strtotime($episodeData->Released)) {
                            $date = new \DateTime($episodeData->Released);
                        } else {
                            $date = null;
                        }

                        $episode = new Episode;
                        $episode
                            ->setName($episodeData->Title)
                            ->setSeason($season)
                            ->setNumber($episodeData->Episode)
                            ->setDate($date);
                        $em->persist($episode);
                    }
            }

            $em->flush();
            return $this->redirect($this->generateUrl('show', array('id' => $show->getId())));

        }else{
            //TODO:implement flashErrorMessage
        }
        return $this->redirect($this->generateUrl('admin_omdb'));

    }
}
