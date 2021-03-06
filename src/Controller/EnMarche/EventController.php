<?php

namespace AppBundle\Controller\EnMarche;

use AppBundle\Event\EventInvitation;
use AppBundle\Event\EventRegistrationCommand;
use AppBundle\Entity\Event;
use AppBundle\Exception\BadUuidRequestException;
use AppBundle\Exception\InvalidUuidException;
use AppBundle\Form\EventInvitationType;
use AppBundle\Form\EventRegistrationType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @Route("/evenements/{uuid}/{slug}", requirements={"uuid": "%pattern_uuid%"})
 */
class EventController extends Controller
{
    /**
     * @Route(name="app_event_show")
     * @Method("GET")
     */
    public function showAction(Event $event): Response
    {
        return $this->render('events/show.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
        ]);
    }

    /**
     * @Route("/ical", name="app_event_export_ical")
     * @Method("GET")
     */
    public function exportIcalAction(Event $event): Response
    {
        return new Response(
            $this->get('jms_serializer')->serialize($event, 'ical'),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/calendar',
                'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT.'; filename='.$event->getSlug().'.ics',
            ]
        );
    }

    /**
     * @Route("/inscription", name="app_event_attend")
     * @Method("GET|POST")
     * @Entity("event", expr="repository.findOneActiveByUuid(uuid)")
     */
    public function attendAction(Request $request, Event $event): Response
    {
        if ($event->isFinished()) {
            throw $this->createNotFoundException(sprintf('Event "%s" is finished and does not accept registrations anymore', $event->getUuid()));
        }

        $committee = $event->getCommittee();

        $command = new EventRegistrationCommand($event, $this->getUser());
        $form = $this->createForm(EventRegistrationType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.registration_handler')->handle($command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.registration.success'));

            return $this->redirectToRoute('app_event_attend_confirmation', [
                'uuid' => (string) $event->getUuid(),
                'slug' => $event->getSlug(),
                'registration' => (string) $command->getRegistrationUuid(),
            ]);
        }

        return $this->render('events/attend.html.twig', [
            'committee_event' => $event,
            'committee' => $committee,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route(
     *   path="/confirmation",
     *   name="app_event_attend_confirmation",
     *   condition="request.query.has('registration')"
     * )
     * @Method("GET")
     */
    public function attendConfirmationAction(Request $request, Event $event): Response
    {
        $manager = $this->get('app.event.registration_manager');

        try {
            if (!$registration = $manager->findRegistration($uuid = $request->query->get('registration'))) {
                throw $this->createNotFoundException(sprintf('Unable to find event registration by its UUID: %s', $uuid));
            }
        } catch (InvalidUuidException $e) {
            throw new BadUuidRequestException($e);
        }

        if (!$registration->matches($event, $this->getUser())) {
            throw $this->createAccessDeniedException('Invalid event registration');
        }

        return $this->render('events/attend_confirmation.html.twig', [
            'committee_event' => $event,
            'committee' => $event->getCommittee(),
            'registration' => $registration,
        ]);
    }

    /**
     * @Route("/invitation", name="app_event_invite")
     * @Method("GET|POST")
     */
    public function inviteAction(Request $request, Event $event): Response
    {
        $eventInvitation = EventInvitation::createFromAdherent($this->getUser());

        $form = $this->createForm(EventInvitationType::class, $eventInvitation)
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EventInvitation $invitation */
            $invitation = $form->getData();

            $this->get('app.event.invitation_handler')->handle($invitation, $event);
            $request->getSession()->set('event_invitations_count', count($invitation->guests));

            return $this->redirectToRoute('app_event_invitation_sent', [
                'uuid' => $event->getUuid(),
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->render('events/invitation.html.twig', [
            'committee_event' => $event,
            'invitation_form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/invitation/merci", name="app_event_invitation_sent")
     * @Method("GET")
     */
    public function invitationSentAction(Request $request, Event $event): Response
    {
        if (!$invitationsCount = $request->getSession()->remove('event_invitations_count')) {
            return $this->redirectToRoute('app_event_invite', [
                'uuid' => $event->getUuid(),
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->render('events/invitation_sent.html.twig', [
            'committee_event' => $event,
            'invitations_count' => $invitationsCount,
        ]);
    }
}
