<?php

declare(strict_types=1);

namespace App\Controller;

use App\JWT\JWT;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.puntu_embed_shared_secret%')]
        private readonly string $puntuEmbedSharedSecret,
        private readonly UserRepository $userRepository,
        private readonly Security $security,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/client-workbench', name: 'app_client_workbench', methods: ['GET'])]
    public function clientWorkbench(): Response
    {
        return $this->render('home/client_workbench.html.twig');
    }

    #[Route('/created-domain-steps', name: 'app_created_domain_steps', methods: ['GET'])]
    public function createdDomainSteps(): Response
    {
        $raw = (string) $this->getParameter('app.domain_create_ns_mandatory_registries');
        $nsMandatoryRegistries = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $nsMandatoryRegistries[] = $part[0] === '.' ? $part : '.' . $part;
        }

        return $this->render('home/created_domain_steps.html.twig', [
            'nsMandatoryRegistries' => $nsMandatoryRegistries,
        ]);
    }

    #[Route('/created-domain-steps-aws-hosted-zone', name: 'app_created_domain_steps_aws_hosted_zone', methods: ['GET'])]
    public function createdDomainStepsAwsHostedZone(): Response
    {
        $raw = (string) $this->getParameter('app.domain_create_ns_mandatory_registries');
        $nsMandatoryRegistries = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $nsMandatoryRegistries[] = $part[0] === '.' ? $part : '.' . $part;
        }

        return $this->render('home/created_domain_steps_aws_hosted_zone.html.twig', [
            'nsMandatoryRegistries' => $nsMandatoryRegistries,
        ]);
    }

    #[Route('/domain-transfer-steps', name: 'app_domain_transfer_steps', methods: ['GET'])]
    public function domainTransferSteps(): Response
    {
        return $this->render('home/domain_transfer_steps.html.twig');
    }

    #[Route('/domain-outgoing-transfer-steps', name: 'app_domain_outgoing_transfer_steps', methods: ['GET'])]
    public function domainOutgoingTransferSteps(): Response
    {
        return $this->render('home/domain_outgoing_transfer_steps.html.twig');
    }

    /**
     * Brizy Cloud iframe: modal shell + postMessage to create Route53 zone and apply Brizy DNS rows.
     */
    #[Route('/embed/buy-domain', name: 'app_embed_buy_domain', methods: ['GET'])]
    public function embedBuyDomain(Request $request): Response
    {
        return $this->embedFrameResponseFromJwt($request, 'embed/buy_domain.html.twig', []);
    }

    /**
     * Brizy Cloud iframe: list domains persisted for this user (local DB).
     */
    #[Route('/embed/manage-domains', name: 'app_embed_manage_domains', methods: ['GET'])]
    public function embedManageDomains(Request $request): Response
    {
        return $this->embedFrameResponseFromJwt($request, 'embed/manage_domains.html.twig', []);
    }

    /**
     * Brizy Cloud iframe: pick a Puntu-owned domain and apply Brizy DNS rows to Route53, then link the project.
     */
    #[Route('/embed/select-owned-domain', name: 'app_embed_select_owned_domain', methods: ['GET'])]
    public function embedSelectOwnedDomain(Request $request): Response
    {
        return $this->embedFrameResponseFromJwt($request, 'embed/select_owned_domain.html.twig', []);
    }

    /**
     * @param array<string, mixed> $twigVars
     */
    private function embedFrameResponseFromJwt(Request $request, string $template, array $twigVars): Response
    {
        $secret = trim($this->puntuEmbedSharedSecret);

        $token = $request->query->get('token');
        if ($secret === '' || $token === null || $token === '') {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = JWT::decode(
                (string) $token,
                JWT::getSignatureKey($secret),
                JWT::getEncryptionKey($secret)
            );
        } catch (\Throwable) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!isset($payload->exp)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $id = $payload->id ?? null;
        $trustedAmemberUserId = is_scalar($id) ? trim((string) $id) : '';
        if ($trustedAmemberUserId === '') {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->getOrCreate($trustedAmemberUserId);
        $this->security->login($user, firewallName: 'main');

        $raw = (string) $this->getParameter('app.brizy_parent_origins');
        $allowed = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $allowed[] = $part;
            }
        }

        $frameAncestors = $allowed === [] ? '*' : implode(' ', $allowed);

        $twigVars['allowed_parent_origins'] = $allowed;
        $twigVars['trusted_amember_user_id'] = $trustedAmemberUserId;

        $response = $this->render($template, $twigVars);
        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.$frameAncestors);

        return $response;
    }
}
