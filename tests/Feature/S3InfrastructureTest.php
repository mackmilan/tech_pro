<?php

namespace Tests\Feature;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class S3InfrastructureTest extends TestCase
{
    public function test_s3_disk_is_configured_with_expected_keys(): void
    {
        $s3Config = config('filesystems.disks.s3');

        $this->assertIsArray($s3Config);
        $this->assertSame('s3', $s3Config['driver']);
        $this->assertArrayHasKey('key', $s3Config);
        $this->assertArrayHasKey('secret', $s3Config);
        $this->assertArrayHasKey('region', $s3Config);
        $this->assertArrayHasKey('bucket', $s3Config);
        $this->assertArrayHasKey('url', $s3Config);
        $this->assertArrayHasKey('endpoint', $s3Config);
        $this->assertArrayHasKey('use_path_style_endpoint', $s3Config);
    }

    public function test_s3_storage_disk_resolves_aws_s3_adapter(): void
    {
        config([
            'filesystems.disks.s3.key' => 'test',
            'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'infocell',
            'filesystems.disks.s3.endpoint' => 'http://localhost:4566',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);

        $disk = Storage::disk('s3');

        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
    }

    public function test_s3_storage_disk_generates_path_style_urls_for_localstack(): void
    {
        config([
            'filesystems.disks.s3.key' => 'test',
            'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'infocell',
            'filesystems.disks.s3.endpoint' => 'http://localhost:4566',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
            'filesystems.disks.s3.url' => null,
        ]);

        $disk = Storage::disk('s3');
        $url = $disk->url('uploads/sample.pdf');

        $this->assertSame('http://localhost:4566/infocell/uploads/sample.pdf', $url);
    }

    public function test_s3_storage_disk_generates_signed_temporary_urls_for_localstack(): void
    {
        config([
            'filesystems.disks.s3.key' => 'test',
            'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'infocell',
            'filesystems.disks.s3.endpoint' => 'http://localhost:4566',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);

        $disk = Storage::disk('s3');
        $temporaryUrl = $disk->temporaryUrl('receipts/invoice.pdf', now()->addMinutes(10));

        $this->assertStringStartsWith('http://localhost:4566/infocell/receipts/invoice.pdf?', $temporaryUrl);
        $this->assertStringContainsString('X-Amz-Signature=', $temporaryUrl);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $temporaryUrl);
    }

    public function test_localstack_s3_live_crud_operations_when_endpoint_is_available(): void
    {
        $endpoint = env('AWS_ENDPOINT', 'http://localhost:4566');
        $bucket = env('AWS_BUCKET', 'infocell') ?: 'infocell';

        // Verificação rápida de conectividade com timeout curto (1s)
        $parsed = parse_url($endpoint);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 4566;

        $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if (! $fp) {
            $this->markTestSkipped("LocalStack não está ativo em {$host}:{$port}. Pulando teste de integração ao vivo.");
        }
        fclose($fp);

        config([
            'filesystems.disks.s3.key' => env('AWS_ACCESS_KEY_ID', 'test') ?: 'test',
            'filesystems.disks.s3.secret' => env('AWS_SECRET_ACCESS_KEY', 'test') ?: 'test',
            'filesystems.disks.s3.region' => env('AWS_DEFAULT_REGION', 'us-east-1') ?: 'us-east-1',
            'filesystems.disks.s3.bucket' => $bucket,
            'filesystems.disks.s3.endpoint' => $endpoint,
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);

        // Garante que o bucket existe antes do teste no LocalStack
        try {
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            if (! $s3Client->doesBucketExist($bucket)) {
                $s3Client->createBucket(['Bucket' => $bucket]);
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Não foi possível verificar/criar bucket no LocalStack: '.$e->getMessage());
        }

        $disk = Storage::disk('s3');
        $testFileName = 'tests/localstack-probe-'.uniqid().'.txt';
        $testContent = 'LocalStack S3 integration probe content';

        $this->assertTrue($disk->put($testFileName, $testContent));
        $this->assertTrue($disk->exists($testFileName));
        $this->assertSame($testContent, $disk->get($testFileName));
        $this->assertTrue($disk->delete($testFileName));
        $this->assertFalse($disk->exists($testFileName));
    }
}

