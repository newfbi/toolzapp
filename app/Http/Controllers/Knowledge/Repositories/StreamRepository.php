<?php

namespace App\Http\Controllers\Knowledge\Repositories;

use App\Models\Chats;
use App\Models\MemoryStream;

class StreamRepository
{
    public function getOrCreateStream(Chats $chat): MemoryStream
    {
        return MemoryStream::firstOrCreate(
            ['chat_id' => $chat->uuid],
            [
                'user_id' => $chat->user_id,
                'agent_id' => $chat->agent_id,
                'conversation_id' => $chat->uuid,
                'conversation_log' => json_encode(['history' => []]),
                'knowledge_base' => json_encode([]),
            ]
        );
    }

    public function getLastStream(Chats $chat): ?MemoryStream
    {
        return MemoryStream::where('chat_id', $chat->uuid)->first();
    }

    public function save(MemoryStream $stream): void
    {
        $stream->save();
    }
}

