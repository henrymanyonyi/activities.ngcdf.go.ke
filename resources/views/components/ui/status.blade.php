@props(['status'])
<x-ui.badge :classes="$status->badgeClasses()" :icon="$status->icon()">{{ $status->label() }}</x-ui.badge>
