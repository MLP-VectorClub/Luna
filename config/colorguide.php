<?php

return [
    // Winterchilla looks for the sprite of an appearance at `<id>.png<id>.png` when rendering the palette image, so it never draws it. Luna
    // matches that output until it is decided otherwise; set to true to draw the sprite next to the colors as the contract describes
    'palette_image_draws_sprite' => (bool) env('PALETTE_IMAGE_DRAWS_SPRITE', false),
];
