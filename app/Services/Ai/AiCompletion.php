<?php

namespace App\Services\Ai;

class AiCompletion
{
    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|list<mixed>|string|null  $rawResponse
     * @param  list<array<string, mixed>>  $attempts
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly int $tokens = 0,
        public readonly bool $ok = true,
        public readonly ?string $error = null,
        public readonly ?string $apiUrl = null,
        public readonly ?int $httpStatus = null,
        public readonly ?array $requestPayload = null,
        public readonly mixed $rawResponse = null,
        public readonly ?string $model = null,
        public readonly array $attempts = [],
    ) {}

    public static function failed(
        string $provider,
        string $error,
        ?string $apiUrl = null,
        ?int $httpStatus = null,
        ?array $requestPayload = null,
        mixed $rawResponse = null,
        ?string $model = null,
        array $attempts = [],
    ): self {
        return new self(
            '',
            $provider,
            0,
            false,
            $error,
            $apiUrl,
            $httpStatus,
            $requestPayload,
            $rawResponse,
            $model,
            $attempts,
        );
    }

    /**
     * Safe snapshot for DB / UI (no secrets).
     *
     * @return array{
     *   provider:string,
     *   api_url:?string,
     *   model:?string,
     *   http_status:?int,
     *   tokens:int,
     *   ok:bool,
     *   error:?string,
     *   request:?array<string,mixed>,
     *   response:mixed,
     *   response_text:string,
     *   attempts:list<array<string,mixed>>
     * }
     */
    public function toLog(): array
    {
        return [
            'provider' => $this->provider,
            'api_url' => $this->apiUrl,
            'model' => $this->model,
            'http_status' => $this->httpStatus,
            'tokens' => $this->tokens,
            'ok' => $this->ok,
            'error' => $this->error,
            'request' => $this->requestPayload,
            'response' => $this->rawResponse,
            'response_text' => $this->text,
            'attempts' => $this->attempts,
        ];
    }
}
