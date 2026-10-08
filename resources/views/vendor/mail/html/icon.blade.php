@props(['name', 'embedFrom' => null])
@php
    // Embedded (cid:) like the seal, so it shows without the portal's public address; previews link the file.
    $path = 'images/mail/'.$name.'.png';
    $src = $embedFrom ? $embedFrom->embed(public_path($path)) : asset($path);
@endphp
<p class="icon"><img src="{{ $src }}" width="56" height="56" alt=""></p>
