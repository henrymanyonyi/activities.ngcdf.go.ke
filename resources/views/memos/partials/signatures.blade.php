{{-- The memo's signature blocks, as in the template: the label ruled across, "Name: X  Signature: …  Date: …", the designation underlined. --}}
@foreach ($signatures as $s)
    <div class="sign">
        <div class="label">{{ $s['label'] }}:</div>
        <table>
            <tr>
                <td style="width: 46%; white-space: nowrap;">Name: @if ($s['name'])<strong>{{ $s['name'] }}</strong>@else………………………………@endif</td>
                <td style="width: 32%; white-space: nowrap;">Signature: ………………………</td>
                <td style="white-space: nowrap;">Date: @if ($s['date']){{ $s['date']->format('d/m/Y') }}@else……………………@endif</td>
            </tr>
        </table>
        <div class="des">{{ $s['designation'] }}</div>
    </div>
@endforeach
