<?php

class MessageController
{
    public function __construct(
        private MessageRepository $repo,
        private EmailService $emailService
    ) {
    }

    public function index(): void
    {
        Auth::check();

        $result = $this->repo->list(
            [
                'status' => $_GET['status'] ?? null,
                'type' => $_GET['type'] ?? null,
                'q' => $_GET['q'] ?? null,
            ],
            (int) ($_GET['limit'] ?? 50),
            (int) ($_GET['page'] ?? 1)
        );

        json_response([
            'ok' => true,
            'summary' => $result['summary'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'data' => array_map('message_to_array', $result['rows']),
        ]);
    }

    public function show(string $id): void
    {
        Auth::check();
        $row = $this->repo->findById($id);
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }
        json_response(['ok' => true, 'data' => message_to_array($row)]);
    }

    public function retry(string $id): void
    {
        Auth::check();
        $row = $this->repo->retry($id);
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }
        json_response(['ok' => true, 'data' => message_to_array($row)]);
    }

    public function processNow(): void
    {
        Auth::check();
        $result = $this->emailService->processPending();
        json_response(array_merge(['ok' => true], $result));
    }
}
