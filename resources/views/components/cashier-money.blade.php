@props(['hundredths'])
{{ config('pos.currency') }} {{ number_format($hundredths / 100, $hundredths % 100 === 0 ? 0 : 2) }}
