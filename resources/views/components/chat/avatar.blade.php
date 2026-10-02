@props([
    'chat' => [],
    'size' => 'md',
])

@if (($chat['type'] ?? 'direct') === 'group')
    <flux:avatar icon="users" color="auto" color:seed="{{ $chat['name'] }}" :size="$size" {{ $attributes }} />
@else
    <flux:avatar :name="$chat['name']" color="auto" :size="$size" {{ $attributes }} />
@endif
