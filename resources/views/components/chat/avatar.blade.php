@props([
    'chat' => [],
    'size' => 'md',
])

@if (($chat['type'] ?? 'direct') === 'group')
    <flux:avatar icon="users" color="auto" color:seed="{{ $chat['title'] }}" :size="$size" {{ $attributes }} />
@else
    <flux:avatar :name="$chat['title']" color="auto" :size="$size" {{ $attributes }} />
@endif
