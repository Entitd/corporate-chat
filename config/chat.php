<?php

return [
    'attachment_max_size_mb' => max(1, (int) env('CHAT_ATTACHMENT_MAX_MB', 50)),
];
