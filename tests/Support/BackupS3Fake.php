<?php

namespace Tests\Support;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;

final class BackupS3Fake
{
    public ?string $failCommand = null;

    public bool $corruptReadback = false;

    public bool $truncateReadback = false;

    public array $commands = [];

    public array $uploads = [];

    public array $metadata = [];

    public array $requests = [];

    public bool $failAfterDelete = false;

    public ?\Closure $onDelete = null;

    public int $aborts = 0;

    public function __construct(public FilesystemAdapter $disk) {}

    public function __invoke(CommandInterface $command): PromiseInterface
    {
        $name = $command->getName();
        $this->commands[] = $name;
        if ($this->failCommand === $name) {
            return Create::rejectionFor(new S3Exception('fixture-access fixture-secret signed-request', $command, ['code' => 'AccessDenied', 'response' => new Response(403)]));
        }
        $key = $command['Key'];
        $this->requests[] = ['name' => $name, 'bucket' => $command['Bucket'], 'key' => $key, 'if_match' => $command['IfMatch'],
            'read_timeout' => $command['@http']['read_timeout'] ?? null];
        switch ($name) {
            case 'HeadObject':
                if (! $this->disk->exists($key)) {
                    return Create::rejectionFor(new S3Exception('not found', $command, ['code' => 'NoSuchKey', 'response' => new Response(404)]));
                }

                return Create::promiseFor(new Result(['ContentLength' => $this->disk->size($key), 'ETag' => '"'.hash('sha256', $this->disk->get($key)).'"', 'Metadata' => $this->metadata[$key] ?? []]));
            case 'CreateMultipartUpload':
                $id = bin2hex(random_bytes(8));
                $this->uploads[$id] = ['key' => $key, 'parts' => [], 'metadata' => $command['Metadata'] ?? []];

                return Create::promiseFor(new Result(['UploadId' => $id]));
            case 'UploadPart':
                $this->uploads[$command['UploadId']]['parts'][$command['PartNumber']] = (string) $command['Body'];

                return Create::promiseFor(new Result(['ETag' => 'part-'.$command['PartNumber']]));
            case 'CompleteMultipartUpload':
                $upload = $this->uploads[$command['UploadId']];
                ksort($upload['parts']);
                $this->disk->put($key, implode('', $upload['parts']));
                $this->metadata[$key] = $upload['metadata'];
                unset($this->uploads[$command['UploadId']]);

                return Create::promiseFor(new Result(['ETag' => 'multipart-etag-is-not-sha256']));
            case 'DeleteObject':
                if ($this->onDelete !== null) {
                    ($this->onDelete)();
                }
                if ($command['IfMatch'] !== '"'.hash('sha256', $this->disk->get($key)).'"') {
                    return Create::rejectionFor(new S3Exception('conditional mismatch', $command, ['code' => 'PreconditionFailed', 'response' => new Response(412)]));
                }
                $this->disk->delete($key);
                unset($this->metadata[$key]);
                if ($this->failAfterDelete) {
                    return Create::rejectionFor(new S3Exception('ambiguous delete response', $command, ['code' => 'ServiceUnavailable', 'response' => new Response(503)]));
                }

                return Create::promiseFor(new Result);
            case 'AbortMultipartUpload':
                unset($this->uploads[$command['UploadId']]);
                $this->aborts++;

                return Create::promiseFor(new Result);
            case 'GetObject':
                $bytes = $this->disk->get($key);
                if ($this->corruptReadback) {
                    $bytes[strlen($bytes) - 20] = chr(ord($bytes[strlen($bytes) - 20]) ^ 1);
                }
                if ($this->truncateReadback) {
                    $bytes = substr($bytes, 0, -8);
                }

                return Create::promiseFor(new Result(['Body' => Utils::streamFor($bytes)]));
            default:
                throw new \LogicException('Unexpected S3 command in isolated test.');
        }
    }
}
