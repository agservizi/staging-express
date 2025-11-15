<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\PdaImportService;

final class PdaImportController
{
    public function __construct(private PdaImportService $pdaImportService)
    {
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $currentUser
     * @return array{success:bool,message:string,warnings?:array<int,string>,errors?:array<int,string>,prefill?:array<string,mixed>}
     */
    public function upload(array $files, array $input, ?array $currentUser = null): array
    {
        $file = $files['pda_file'] ?? null;
        return $this->pdaImportService->processUpload(
            is_array($file) ? $file : null,
            $input,
            $currentUser
        );
    }
}
