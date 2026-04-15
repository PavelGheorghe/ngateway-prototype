<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\User;
use App\Repository\RegistryContactRepository;
use App\Service\BrizyDomainPurchaseService;
use App\Service\ContactService;
use App\Service\CustomerPersistenceService;
use App\Service\CoreGatewayClient;
use App\Service\DomainService;
use App\Service\HostService;
use App\Service\PollService;
use App\Service\Route53DnssecKmsService;
use App\Service\Route53HostedZoneService;
use App\Service\Route53RecordSetService;
use App\Service\ZoneService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

final class ApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BrizyDomainPurchaseService $brizyDomainPurchaseService,
        private readonly ContactService $contactService,
        private readonly CustomerPersistenceService $customerPersistenceService,
        private readonly DomainService $domainService,
        private readonly HostService $hostService,
        private readonly PollService $pollService,
        private readonly Route53HostedZoneService $route53HostedZoneService,
        private readonly Route53RecordSetService $route53RecordSetService,
        private readonly Route53DnssecKmsService $route53DnssecKmsService,
        private readonly ZoneService $zoneService,
        private readonly RegistryContactRepository $registryContactRepository,
    ) {
    }

    /**
     * Brizy embed step 2: return stored registry contact id for amember user + TLD (if previously saved).
     */
    #[Route('/api/brizy/registry-contact', methods: ['GET'])]
    public function brizyRegistryContact(Request $request): JsonResponse
    {
        $amember = $this->authenticatedAmemberUserId();
        $registryId = (string) $request->query->get('registryId', '');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        if ($registryId === '') {
            return $this->apiJson(['success' => false, 'message' => 'registryId required'], 400);
        }

        $contactId = $this->registryContactRepository->findContactIdForAmemberAndRegistry($amember, $registryId);
        if ($contactId === null) {
            return $this->apiJson(['success' => true, 'found' => false]);
        }

        return $this->apiJson([
            'success' => true,
            'found' => true,
            'contactId' => $contactId,
            'registryId' => $registryId,
        ]);
    }

    #[Route('/api/contact/create', methods: ['POST'])]
    public function createContact(Request $request): JsonResponse
    {
        $body = $this->mergeAmemberFromUser($this->jsonBody($request));
        $response = $this->contactService->create($body);
        try {
            $this->customerPersistenceService->persistAfterContactCreate($body, $response);
        } catch (\Throwable) {
            // Local DB persistence must not affect CORE contact.create response
        }

        return $this->apiJson($response);
    }

    #[Route('/api/contact/list', methods: ['GET'])]
    public function listContacts(Request $request): JsonResponse
    {
        return $this->apiJson($this->contactService->list([
            'registryId' => (string) $request->query->get('registryId', '.com'),
        ]));
    }

    #[Route('/api/contact/inquire', methods: ['GET'])]
    public function inquireContact(Request $request): JsonResponse
    {
        return $this->apiJson($this->contactService->inquire([
            'registryId' => (string) $request->query->get('registryId', '.com'),
            'contactId' => (string) $request->query->get('contactId', ''),
        ]));
    }

    #[Route('/api/contact/modify', methods: ['POST'])]
    public function modifyContact(Request $request): JsonResponse
    {
        $response = $this->contactService->modify($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/host/create', methods: ['POST'])]
    public function createHost(Request $request): JsonResponse
    {
        return $this->apiJson($this->hostService->create($this->jsonBody($request)));
    }

    /**
     * Brizy embed: create a public hosted zone for domainName, then UPSERT Brizy-shaped dnsRecords (A/CNAME).
     */
    #[Route('/api/brizy/hosted-zone-with-dns', methods: ['POST'])]
    public function brizyHostedZoneWithDns(Request $request): JsonResponse
    {
        $auth = $this->authenticatedAmemberUserId();
        $body = $this->jsonBody($request);
        $body['amemberUserId'] = $auth;
        $domainName = trim((string) ($body['domainName'] ?? ''));
        $dnsRecords = $body['dnsRecords'] ?? null;
        if ($domainName === '') {
            return $this->apiJson(['success' => false, 'message' => 'domainName required'], 400);
        }
        if (!is_array($dnsRecords)) {
            return $this->apiJson(['success' => false, 'message' => 'dnsRecords must be an array'], 400);
        }

        $hz = $this->route53HostedZoneService->createForDomain($domainName, []);
        if (!($hz['success'] ?? false)) {
            return $this->apiJson($hz, 400);
        }

        $hostedZoneId = (string) ($hz['hostedZoneId'] ?? '');
        $upsert = $this->route53RecordSetService->upsertBrizyDnsRecords($hostedZoneId, $dnsRecords);
        if (!($upsert['success'] ?? false)) {
            return $this->apiJson([
                'success' => false,
                'message' => $upsert['message'] ?? 'DNS update failed',
                'hostedZone' => $hz,
                'dnsRecordsError' => $upsert,
            ], 400);
        }

        return $this->apiJson([
            'success' => true,
            'hostedZone' => $hz,
            'dnsRecords' => $upsert,
            'amemberUserId' => $auth,
            'projectId' => $body['projectId'] ?? null,
        ]);
    }

    /**
     * Brizy embed step 3: hosted zone + Brizy DNS + domain.create with Route53 delegation NS.
     */
    #[Route('/api/brizy/domain-purchase', methods: ['POST'])]
    public function brizyDomainPurchase(Request $request): JsonResponse
    {
        $auth = $this->authenticatedAmemberUserId();
        $body = $this->jsonBody($request);
        $body['amemberUserId'] = $auth;
        $response = $this->brizyDomainPurchaseService->complete($body);
        $status = ($response['success'] ?? false) ? 200 : 400;

        if (($response['success'] ?? false) && isset($response['summary']) && is_array($response['summary'])) {
            $summary = $response['summary'];
            $amember = (string) ($response['amemberUserId'] ?? $auth);
            try {
                $this->customerPersistenceService->persistAfterDomainPurchase(
                    $amember,
                    (string) ($summary['domain'] ?? ''),
                    (string) ($summary['registryId'] ?? ''),
                    (string) ($summary['contactId'] ?? '')
                );
            } catch (\Throwable) {
            }
        }

        return $this->apiJson($response, $status);
    }

    #[Route('/api/aws/route53/hosted-zone', methods: ['POST'])]
    public function awsRoute53HostedZone(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $domainName = trim((string) ($body['domainName'] ?? ''));
        $registryId = trim((string) ($body['registryId'] ?? ''));
        $fqdn = CoreGatewayClient::fqdnForRegistry($domainName, $registryId);
        $enableDnssec = filter_var($body['enableDnssec'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $dnssecKmsKeyArn = trim((string) ($body['dnssecKmsKeyArn'] ?? ''));
        $response = $this->route53HostedZoneService->createForDomain($fqdn, [
            'enableDnssec' => $enableDnssec,
            'dnssecKmsKeyArn' => $dnssecKmsKeyArn,
        ]);
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    /**
     * Create a new customer-managed ECC_NIST_P256 signing key in us-east-1 with a Route 53 DNSSEC–compatible key policy.
     */
    #[Route('/api/aws/kms/dnssec-signing-key', methods: ['POST'])]
    public function awsKmsCreateDnssecSigningKey(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $description = isset($body['description']) ? trim((string) $body['description']) : null;
        $response = $this->route53DnssecKmsService->createDnssecSigningKey(
            ($description !== null && $description !== '') ? $description : null,
        );
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/check', methods: ['POST'])]
    public function domainCheck(Request $request): JsonResponse
    {
        return $this->apiJson($this->domainService->check($this->jsonBody($request)));
    }

    #[Route('/api/domain/create', methods: ['POST'])]
    public function domainCreate(Request $request): JsonResponse
    {
        $response = $this->domainService->create($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/inquire', methods: ['GET'])]
    public function domainInquire(Request $request): JsonResponse
    {
        return $this->apiJson($this->domainService->inquire(
            (string) $request->query->get('name', ''),
            (string) $request->query->get('registryId', '.com'),
        ));
    }

    #[Route('/api/domain/authinfo-request', methods: ['POST'])]
    public function domainAuthinfoRequest(Request $request): JsonResponse
    {
        $response = $this->domainService->authinfoRequest($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/inquire-transfer', methods: ['POST'])]
    public function domainInquireTransfer(Request $request): JsonResponse
    {
        $response = $this->domainService->inquireForTransfer($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/transfer', methods: ['POST'])]
    public function domainTransfer(Request $request): JsonResponse
    {
        $response = $this->domainService->transferRequest($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/transfer-reply', methods: ['POST'])]
    public function domainTransferReply(Request $request): JsonResponse
    {
        $response = $this->domainService->transferReply($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/inquire-by-contact', methods: ['POST'])]
    public function domainInquireByContact(Request $request): JsonResponse
    {
        $response = $this->domainService->inquireByContact($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/inquire-batch-by-contact', methods: ['POST'])]
    public function domainInquireBatchByContact(Request $request): JsonResponse
    {
        $response = $this->domainService->inquireBatchForContact($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/domain/renew', methods: ['POST'])]
    public function domainRenew(Request $request): JsonResponse
    {
        return $this->apiJson($this->domainService->renew($this->jsonBody($request)));
    }

    #[Route('/api/domain/delete', methods: ['POST'])]
    public function domainDelete(Request $request): JsonResponse
    {
        return $this->apiJson($this->domainService->delete($this->jsonBody($request)));
    }

    #[Route('/api/domain/status-modify', methods: ['POST'])]
    public function domainStatusModify(Request $request): JsonResponse
    {
        return $this->apiJson($this->domainService->statusModify($this->jsonBody($request)));
    }

    #[Route('/api/domain/modify', methods: ['POST'])]
    public function domainModify(Request $request): JsonResponse
    {
        return $this->apiJson($this->domainService->modify($this->jsonBody($request)));
    }

    #[Route('/api/poll/request', methods: ['POST'])]
    public function pollRequest(Request $request): JsonResponse
    {
        return $this->apiJson($this->pollService->request($this->jsonBody($request)));
    }

    #[Route('/api/poll/acknowledge', methods: ['POST'])]
    public function pollAcknowledge(Request $request): JsonResponse
    {
        $response = $this->pollService->acknowledge($this->jsonBody($request));
        $status = ($response['success'] ?? false) ? 200 : 400;

        return $this->apiJson($response, $status);
    }

    #[Route('/api/poll/status', methods: ['POST'])]
    public function pollStatus(Request $request): JsonResponse
    {
        return $this->apiJson($this->pollService->status($this->jsonBody($request)));
    }

    #[Route('/api/zone/create', methods: ['POST'])]
    public function zoneCreate(Request $request): JsonResponse
    {
        return $this->apiJson($this->zoneService->create($this->jsonBody($request)));
    }

    #[Route('/api/zone/inquire', methods: ['GET'])]
    public function zoneInquire(Request $request): JsonResponse
    {
        return $this->apiJson($this->zoneService->inquire((string) $request->query->get('zoneId', '')));
    }

    #[Route('/api/zone/modify', methods: ['POST'])]
    public function zoneModify(Request $request): JsonResponse
    {
        return $this->apiJson($this->zoneService->modify($this->jsonBody($request)));
    }

    #[Route('/api/zone/delete', methods: ['POST'])]
    public function zoneDelete(Request $request): JsonResponse
    {
        return $this->apiJson($this->zoneService->delete($this->jsonBody($request)));
    }

    /**
     * Domains persisted for the authenticated user (e.g. after Brizy embed purchase).
     */
    #[Route('/api/domains', methods: ['GET'])]
    public function listDomains(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->apiJson(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $domains = $this->entityManager->getRepository(Domain::class)->findBy(
            ['user' => $user],
            ['domainFqdn' => 'ASC']
        );

        $list = [];
        foreach ($domains as $d) {
            $list[] = ['domainFqdn' => $d->getDomainFqdn()];
        }

        return $this->apiJson(['success' => true, 'domains' => $list]);
    }

    #[Route('/api/{path}', requirements: ['path' => '.+'], methods: ['OPTIONS'])]
    public function preflight(): Response
    {
        return new Response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ]);
    }

    private function authenticatedAmemberUserId(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Expected authenticated User');
        }

        return $user->getAmemberUserId();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function mergeAmemberFromUser(array $body): array
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            $body['amemberUserId'] = $user->getAmemberUserId();
        }

        return $body;
    }

    private function jsonBody(Request $request): array
    {
        $payload = json_decode($request->getContent() ?: '{}', true);

        return is_array($payload) ? $payload : [];
    }

    private function apiJson(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ]);
    }
}
