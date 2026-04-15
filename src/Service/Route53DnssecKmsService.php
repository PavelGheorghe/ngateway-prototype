<?php

declare(strict_types=1);

namespace App\Service;

use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Aws\Sts\StsClient;

/**
 * Creates customer-managed KMS keys suitable for Route 53 DNSSEC signing.
 *
 * @see https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/dns-configuring-dnssec-cmk-requirements.html
 */
final class Route53DnssecKmsService
{
    /** Route 53 DNSSEC requires the CMK to reside in us-east-1. */
    private const DNSSEC_KMS_REGION = 'us-east-1';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createDnssecSigningKey(?string $description = null): array
    {
        if (trim($this->accessKeyId) === '' || trim($this->secretAccessKey) === '') {
            return [
                'success' => false,
                'code' => 'config',
                'message' => 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set to create a KMS key.',
            ];
        }

        $creds = [
            'key' => $this->accessKeyId,
            'secret' => $this->secretAccessKey,
        ];

        try {
            $sts = new StsClient([
                'version' => '2011-06-15',
                'region' => self::DNSSEC_KMS_REGION,
                'credentials' => $creds,
            ]);
            $identity = $sts->getCallerIdentity();
            $accountId = trim((string) ($identity['Account'] ?? ''));
            if ($accountId === '') {
                return [
                    'success' => false,
                    'code' => 'aws',
                    'message' => 'Could not determine AWS account ID (sts:GetCallerIdentity).',
                ];
            }
        } catch (AwsException $e) {
            return [
                'success' => false,
                'code' => $e->getAwsErrorCode() ?: 'aws',
                'message' => 'sts:GetCallerIdentity failed: ' . $this->awsExceptionMessage($e),
            ];
        }

        $policy = $this->buildKeyPolicyForAccount($accountId);
        $desc = trim((string) ($description ?? ''));
        if ($desc === '') {
            $desc = 'Route 53 DNSSEC signing (puntu-symfony-app ' . gmdate('Y-m-d') . ')';
        }

        try {
            $kms = new KmsClient([
                'version' => '2014-11-01',
                'region' => self::DNSSEC_KMS_REGION,
                'credentials' => $creds,
            ]);

            $result = $kms->createKey([
                'Description' => $desc,
                'KeyUsage' => 'SIGN_VERIFY',
                'KeySpec' => 'ECC_NIST_P256',
                'Origin' => 'AWS_KMS',
                'Policy' => json_encode($policy, JSON_THROW_ON_ERROR),
                'Tags' => [
                    ['TagKey' => 'Purpose', 'TagValue' => 'route53-dnssec'],
                    ['TagKey' => 'ManagedBy', 'TagValue' => 'puntu-symfony-app'],
                ],
            ]);
        } catch (AwsException $e) {
            return [
                'success' => false,
                'code' => $e->getAwsErrorCode() ?: 'aws',
                'message' => $this->awsExceptionMessage($e),
            ];
        } catch (\JsonException $e) {
            return [
                'success' => false,
                'code' => 'internal',
                'message' => 'Failed to encode KMS key policy: ' . $e->getMessage(),
            ];
        }

        $meta = $result['KeyMetadata'] ?? [];
        $keyId = isset($meta['KeyId']) ? (string) $meta['KeyId'] : '';
        $arn = isset($meta['Arn']) ? (string) $meta['Arn'] : '';

        if ($keyId === '' || $arn === '') {
            return [
                'success' => false,
                'code' => 'aws',
                'message' => 'KMS CreateKey succeeded but KeyId/Arn missing in response.',
            ];
        }

        $aliasName = 'alias/puntu-route53-dnssec-' . bin2hex(random_bytes(4));
        try {
            $kms->createAlias([
                'AliasName' => $aliasName,
                'TargetKeyId' => $keyId,
            ]);
        } catch (AwsException) {
            $aliasName = '';
        }

        return [
            'success' => true,
            'code' => '10000',
            'message' => 'KMS key created in ' . self::DNSSEC_KMS_REGION . ' for Route 53 DNSSEC (ECC_NIST_P256). Key policy includes dnssec-route53.amazonaws.com.',
            'keyId' => $keyId,
            'keyArn' => $arn,
            'alias' => $aliasName !== '' ? $aliasName : null,
            'region' => self::DNSSEC_KMS_REGION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildKeyPolicyForAccount(string $accountId): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'Enable IAM User Permissions',
                    'Effect' => 'Allow',
                    'Principal' => [
                        'AWS' => 'arn:aws:iam::' . $accountId . ':root',
                    ],
                    'Action' => 'kms:*',
                    'Resource' => '*',
                ],
                [
                    'Sid' => 'Allow Route 53 DNSSEC Service',
                    'Effect' => 'Allow',
                    'Principal' => [
                        'Service' => 'dnssec-route53.amazonaws.com',
                    ],
                    'Action' => [
                        'kms:DescribeKey',
                        'kms:GetPublicKey',
                        'kms:Sign',
                    ],
                    'Resource' => '*',
                ],
                [
                    'Sid' => 'Allow Route 53 DNSSEC to CreateGrant',
                    'Effect' => 'Allow',
                    'Principal' => [
                        'Service' => 'dnssec-route53.amazonaws.com',
                    ],
                    'Action' => [
                        'kms:CreateGrant',
                    ],
                    'Resource' => '*',
                    'Condition' => [
                        'Bool' => [
                            'kms:GrantIsForAWSResource' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function awsExceptionMessage(AwsException $e): string
    {
        if (method_exists($e, 'getAwsErrorMessage')) {
            $m = (string) $e->getAwsErrorMessage();
            if ($m !== '') {
                return $m;
            }
        }

        return $e->getMessage();
    }
}
