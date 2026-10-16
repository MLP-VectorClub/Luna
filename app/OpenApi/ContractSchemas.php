<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas that describe responses of the API. Their shapes match what the API returns and were checked with scripts/validate-responses.py.
 */
#[OA\Schema(
    schema: 'AccountRole',
    type: 'string',
    description: 'The role of a user account (`guest` only exists as a permission level for signed-out visitors). A developer is shown under the label of the `dev_role_label` setting on public payloads.',
    enum: ['user', 'member', 'assistant', 'staff', 'admin', 'developer'],
)]
#[OA\Schema(
    schema: 'Appearance',
    type: 'object',
    description: 'Represents an entry in the color guide',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/SlimAppearance'),
        new OA\Schema(ref: '#/components/schemas/ListOfColorGroups'),
    ],
)]
#[OA\Schema(
    schema: 'AppearanceList',
    type: 'object',
    description: 'A page of appearances under the appearances key',
    required: ['appearances', 'pagination'],
    additionalProperties: false,
    properties: [
        new OA\Property(
            property: 'appearances',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Appearance'),
        ),
        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
    ],
)]
#[OA\Schema(
    schema: 'AvatarProvider',
    type: 'string',
    description: 'Where a user\'s avatar comes from. Winterchilla only knows DeviantArt avatars; other implementations may also use Discord or Gravatar.',
    enum: ['deviantart', 'discord', 'gravatar'],
)]
#[OA\Schema(
    schema: 'BlendMode',
    type: 'string',
    description: 'How a color layer combines with what is under it',
    enum: [
        'normal',
        'multiply',
        'screen',
        'overlay',
        'darken',
        'lighten',
        'color-dodge',
        'color-burn',
        'hard-light',
        'soft-light',
        'difference',
        'exclusion',
    ],
)]
#[OA\Schema(
    schema: 'Color',
    type: 'object',
    description: 'A color entry. Colors may link to other colors, in which case `linkedTo` will be set to the link target, but `hex` will always point to the value that should be displayed.',
    required: ['id', 'label', 'order', 'hex'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'label', type: 'string', description: 'The name of the color', example: 'Fill'),
        new OA\Property(property: 'order', ref: '#/components/schemas/Order'),
        new OA\Property(
            property: 'hex',
            type: 'string',
            format: '#RRGGBB',
            description: 'The color value in uppercase hexadecimal form, including a # prefix',
            nullable: true,
            example: '#6181B6',
        ),
        new OA\Property(
            property: 'opacity',
            type: 'integer',
            minimum: 1,
            maximum: 99,
            nullable: true,
            description: 'How opaque the color is drawn in percent, for colors that are semi-transparent in the sprite. Null means fully opaque',
        ),
        new OA\Property(
            property: 'blendMode',
            nullable: true,
            description: 'How a semi-transparent color combines with what is under it. Null when neither opacity nor blend mode is set, otherwise normal unless said otherwise',
            allOf: [new OA\Schema(ref: '#/components/schemas/BlendMode')],
        ),
        new OA\Property(
            property: 'linkedTo',
            description: 'This field used to indicate if this color was linked to another color, however, this feature was removed and this field now only ever returns null',
            nullable: true,
            deprecated: true,
            example: null,
            allOf: [new OA\Schema(ref: '#/components/schemas/Color')],
        ),
    ],
)]
#[OA\Schema(
    schema: 'CutieMark',
    type: 'object',
    description: 'A cutie mark entry',
    required: ['id', 'viewUrl', 'facing', 'rotation'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(
            property: 'viewUrl',
            type: 'string',
            description: 'The URL used for displaying the cutie mark SVG file.',
        ),
        new OA\Property(
            property: 'facing',
            type: 'string',
            description: 'The direction the character is facing when this cutie mark should be used. `null` means the image is symmetrical.',
            nullable: true,
            enum: ['left', 'right', null],
        ),
        new OA\Property(
            property: 'favMe',
            type: 'string',
            description: 'Optional ID of a deviation on DeviantArt that is the original source of this cutie mark vector.',
            nullable: true,
        ),
        new OA\Property(property: 'rotation', type: 'integer', minimum: -45, maximum: 45, default: 0),
        new OA\Property(property: 'contributor', ref: '#/components/schemas/User'),
        new OA\Property(
            property: 'label',
            type: 'string',
            description: 'Optional label in case the cutie mark warrants additional information.',
        ),
    ],
)]
#[OA\Schema(
    schema: 'DetailedAppearance',
    type: 'object',
    description: 'An appearance object containing the full range of information available',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Appearance'),
        new OA\Schema(
            type: 'object',
            required: ['cutieMarks', 'canEdit', 'relatedAppearances', 'relatedShows'],
            properties: [
                new OA\Property(
                    property: 'relatedAppearances',
                    type: 'array',
                    description: 'Appearances this one is linked to, e.g. other versions of the same character',
                    items: new OA\Items(ref: '#/components/schemas/PreviewAppearance'),
                ),
                new OA\Property(
                    property: 'relatedShows',
                    type: 'array',
                    description: 'Shows this appearance is linked to',
                    items: new OA\Items(ref: '#/components/schemas/ShowListItem'),
                ),
                new OA\Property(
                    property: 'canEdit',
                    type: 'boolean',
                    description: 'Whether the current user may edit this appearance (its owner, or staff)',
                ),
                new OA\Property(
                    property: 'majorChanges',
                    type: 'array',
                    description: 'The major changes of an official appearance, newest first (always empty for personal guide appearances); `user` is only sent to staff',
                    items: new OA\Items(
                        type: 'object',
                        additionalProperties: false,
                        required: ['id', 'reason', 'user', 'createdAt'],
                        properties: [
                            new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
                            new OA\Property(property: 'reason', type: 'string'),
                            new OA\Property(
                                property: 'user',
                                type: 'object',
                                nullable: true,
                                additionalProperties: false,
                                required: ['id', 'name'],
                                properties: [
                                    new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
                                    new OA\Property(property: 'name', type: 'string'),
                                ],
                            ),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        ],
                    ),
                ),
                new OA\Property(
                    property: 'token',
                    nullable: true,
                    ref: '#/components/schemas/AppearanceToken',
                    description: 'The share token of a private appearance (the `token` query parameter that opens it for anybody), only for those who may edit it; null otherwise',
                ),
                new OA\Property(
                    property: 'cutieMarks',
                    type: 'array',
                    minItems: 0,
                    items: new OA\Items(ref: '#/components/schemas/CutieMark'),
                ),
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    type: 'object',
    required: ['message'],
    additionalProperties: true,
    properties: [
        new OA\Property(
            property: 'message',
            type: 'string',
            description: 'An error message describing what caused the request to fail',
            example: 'The given data was invalid.',
        ),
    ],
)]
#[OA\Schema(schema: 'File', type: 'string', format: 'binary')]
#[OA\Schema(
    schema: 'GitInfo',
    type: 'object',
    description: 'Contains information about the server\'s current revision',
    required: ['commitId', 'commitTime'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'commitId', type: 'string', example: 'a1bfc6d'),
        new OA\Property(property: 'commitTime', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(schema: 'GuideName', type: 'string', default: 'pony', enum: ['pony', 'eqg'])]
#[OA\Schema(schema: 'GuidePageSize', type: 'integer', minimum: 7, maximum: 20, default: 7)]
#[OA\Schema(
    schema: 'LogItem',
    type: 'object',
    required: ['id', 'type', 'typeLabel', 'initiator', 'ip', 'createdAt', 'hasDetails'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'type', type: 'string', example: 'rolechange'),
        new OA\Property(property: 'typeLabel', type: 'string', example: 'User group change'),
        new OA\Property(
            property: 'initiator',
            description: 'Null when the web server itself made the change',
            nullable: true,
            oneOf: [new OA\Schema(ref: '#/components/schemas/PostUser')],
        ),
        new OA\Property(property: 'ip', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'hasDetails',
            type: 'boolean',
            description: 'Whether GET /admin/logs/{id} has anything to show',
        ),
    ],
)]
#[OA\Schema(
    schema: 'MajorChange',
    type: 'object',
    required: ['id', 'reason', 'appearance', 'createdAt'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'appearance', ref: '#/components/schemas/PreviewAppearance'),
        new OA\Property(
            property: 'user',
            description: 'Who made the change; only sent to staff',
            nullable: true,
            oneOf: [new OA\Schema(ref: '#/components/schemas/User')],
        ),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'Order',
    type: 'number',
    description: 'Used for displaying items in a specific order. The API guarantees that array return values are sorted in ascending order based on this property.',
)]
#[OA\Schema(
    schema: 'Post',
    type: 'object',
    description: 'Represents an art post (request or reservation)',
    required: ['label'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'label', type: 'string', description: 'Display label for the post', nullable: true),
        new OA\Property(
            property: 'type',
            type: 'string',
            description: 'Request type, only present for requests',
            enum: ['chr', 'obj', 'bg'],
        ),
        new OA\Property(
            property: 'reservedAt',
            type: 'string',
            format: 'date-time',
            description: 'Date the request was reserved, or an empty string if not set. Only present for developers viewing a reserved request',
        ),
        new OA\Property(
            property: 'postedAt',
            type: 'string',
            format: 'date-time',
            description: 'Only present for developers',
        ),
        new OA\Property(
            property: 'finishedAt',
            type: 'string',
            format: 'date-time',
            description: 'Date the post was finished, or an empty string if not set. Only present for developers when the post is reserved and finished',
        ),
    ],
)]
#[OA\Schema(
    schema: 'PreviewAppearance',
    type: 'object',
    description: 'The barest of properties for an appearance, enough to show a small colored preview',
    required: ['id', 'label', 'guide', 'ownerId', 'previewData', 'nutshellNames'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(
            property: 'nutshellNames',
            type: 'array',
            description: 'Names the appearance can be shown as in the 2020 nutshell names mode. When the visitor\'s cg_nutshell preference is on, show one of them (picked at random) instead of the label, or the lowercased label when the list is empty. Always empty for personal guide appearances, which are never renamed',
            items: new OA\Items(type: 'string'),
        ),
        new OA\Property(
            property: 'ownerId',
            type: 'integer',
            description: 'ID of the user whose personal guide the appearance belongs to; null for the official guides',
            nullable: true,
        ),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(
            property: 'guide',
            type: 'string',
            description: 'The guide the appearance belongs to; null for personal guide appearances',
            nullable: true,
        ),
        new OA\Property(
            property: 'previewData',
            type: 'array',
            description: 'Up to four hex colors (#rrggbb) that represent the appearance',
            items: new OA\Items(type: 'string'),
        ),
    ],
)]
#[OA\Schema(
    schema: 'PreviewsIndicator',
    type: 'boolean',
    description: 'Optional parameter that indicates whether you would like to get preview image data with the request. Typically unneccessary unless you want to display a temporary image while the larger image loads.',
    enum: [true],
)]
#[OA\Schema(
    schema: 'PrivateAppearance',
    type: 'object',
    description: 'Represents a color guide appearance',
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'label', type: 'string', description: 'The display name of the appearance'),
        new OA\Property(
            property: 'notes',
            type: 'string',
            description: 'Raw (markdown) source of the appearance\'s notes',
            nullable: true,
        ),
        new OA\Property(
            property: 'private',
            type: 'boolean',
            description: 'Whether this appearance is only visible to its owner and staff',
        ),
    ],
)]
#[OA\Schema(
    schema: 'PrivateColor',
    type: 'object',
    description: 'Represents a single color within a color group',
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'order', type: 'integer', minimum: 1),
        new OA\Property(property: 'label', type: 'string', minLength: 3, maxLength: 30),
        new OA\Property(property: 'hex', type: 'string', description: 'Hex color code, e.g. #FF0000', nullable: true),
        new OA\Property(property: 'opacity', type: 'integer', minimum: 1, maximum: 99, nullable: true),
        new OA\Property(
            property: 'blendMode',
            nullable: true,
            allOf: [new OA\Schema(ref: '#/components/schemas/BlendMode')],
        ),
    ],
)]
#[OA\Schema(
    schema: 'PrivateColorGroup',
    type: 'object',
    description: 'Represents a color group belonging to an appearance',
    required: ['id', 'appearanceId', 'order', 'label'],
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'appearanceId', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'order', type: 'integer', minimum: 1),
        new OA\Property(property: 'label', type: 'string', minLength: 2, maxLength: 30),
        new OA\Property(
            property: 'colors',
            type: 'array',
            description: 'Only present in the GET response',
            items: new OA\Items(ref: '#/components/schemas/PrivateColor'),
        ),
    ],
)]
#[OA\Schema(
    schema: 'RegexPattern',
    type: 'object',
    description: 'A regular expression in the form JavaScript\'s RegExp constructor takes it',
    required: ['source', 'flags'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'flags', type: 'string', example: 'i'),
    ],
)]
#[OA\Schema(
    schema: 'SessionUpdating',
    type: 'object',
    required: ['sessionUpdating'],
    additionalProperties: false,
    properties: [
        new OA\Property(
            property: 'sessionUpdating',
            type: 'boolean',
            description: 'If this value is true the DeviantArt access token expired and the backend is updating it in the background. Future requests should be made to the appropriate endpoint periodically (TODO) to check whether the session update was successful and the user should be logged out if it wasn\'t.',
        ),
    ],
)]
#[OA\Schema(
    schema: 'ShowListItem',
    type: 'object',
    required: ['id', 'type', 'title', 'season', 'episode', 'parts', 'no', 'airs'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'type', type: 'string', enum: ['episode', 'movie', 'short', 'special']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'season', type: 'integer', nullable: true),
        new OA\Property(property: 'episode', type: 'integer', nullable: true),
        new OA\Property(property: 'parts', type: 'integer', nullable: true),
        new OA\Property(property: 'no', type: 'integer', description: 'Overall number of the show', nullable: true),
        new OA\Property(property: 'airs', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'SidebarUsefulLink',
    type: 'object',
    required: ['id', 'label', 'url', 'minRole'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'url', type: 'string'),
        new OA\Property(property: 'title', type: 'string', nullable: true),
        new OA\Property(property: 'minRole', type: 'string', description: 'The lowest role that can see this link'),
    ],
)]
#[OA\Schema(
    schema: 'SlimAppearance',
    type: 'object',
    description: 'A less heavy version of the regular Appearance schema',
    required: [
        'id',
        'label',
        'createdAt',
        'notes',
        'tags',
        'sprite',
        'hasCutieMarks',
        'guide',
        'ownerId',
        'previewData',
    ],
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/ZeroBasedId'),
        new OA\Property(
            property: 'guide',
            type: 'string',
            description: 'The official guide the appearance belongs to; null for personal guide appearances (see ownerId)',
            nullable: true,
            enum: ['pony', 'eqg', null],
        ),
        new OA\Property(
            property: 'ownerId',
            type: 'integer',
            description: 'ID of the user whose personal guide the appearance belongs to; null for the official guides',
            nullable: true,
        ),
        new OA\Property(
            property: 'previewData',
            type: 'array',
            description: 'Up to four hex colors (#rrggbb) that represent the appearance, for small previews and cards',
            items: new OA\Items(type: 'string'),
        ),
        new OA\Property(
            property: 'label',
            type: 'string',
            description: 'The name of the appearance',
            example: 'Twinkle Sprinkle',
        ),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'notes',
            type: 'string',
            format: 'html',
            nullable: true,
            example: 'Far legs use darker colors. Based on <strong>S2E21</strong>.',
        ),
        new OA\Property(
            property: 'tags',
            type: 'array',
            minItems: 0,
            items: new OA\Items(ref: '#/components/schemas/SlimGuideTag'),
        ),
        new OA\Property(
            property: 'sprite',
            description: 'The sprite that belongs to this appearance, or null if there is none',
            nullable: true,
            oneOf: [new OA\Schema(ref: '#/components/schemas/Sprite')],
        ),
        new OA\Property(
            property: 'hasCutieMarks',
            type: 'boolean',
            description: 'Indicates whether there are any cutie marks tied to this appearance',
        ),
        new OA\Property(
            property: 'lastMajorChange',
            type: 'string',
            format: 'date-time',
            nullable: true,
            description: 'When the appearance\'s last major change was recorded; not part of Winterchilla\'s contract, which renders it as HTML in the guide list. Not set on the compact items of the full list',
        ),
    ],
)]
#[OA\Schema(
    schema: 'SlimAppearanceList',
    type: 'object',
    description: 'All appearances of a guide in the requested order, under the appearances key, plus how to group them',
    required: ['appearances', 'groups'],
    additionalProperties: false,
    properties: [
        new OA\Property(
            property: 'appearances',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/SlimAppearance'),
        ),
        new OA\Property(
            property: 'groups',
            type: 'array',
            description: 'Sections of the list: by tag group for `relevance` (the staff-managed order within), by first letter for `label`, none for `added`',
            items: new OA\Items(
                type: 'object',
                required: ['name', 'appearanceIds'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(
                        property: 'appearanceIds',
                        type: 'array',
                        items: new OA\Items(ref: '#/components/schemas/ZeroBasedId'),
                    ),
                ],
            ),
        ),
    ],
)]
#[OA\Schema(
    schema: 'SlimGuideTag',
    type: 'object',
    required: ['id', 'name', 'type'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(
            property: 'name',
            type: 'string',
            description: 'Tag name (all lowercase)',
            minLength: 1,
            maxLength: 255,
            example: 'mane six',
        ),
        new OA\Property(
            property: 'type',
            type: 'string',
            description: 'Category this tag belongs to, or null if uncategorized',
            nullable: true,
            enum: ['app', 'cat', 'gen', 'spec', 'char', 'warn', null],
        ),
        new OA\Property(
            property: 'synonymOf',
            ref: '#/components/schemas/OneBasedId',
            nullable: true,
            description: 'The tag this one is a synonym of, null for regular tags. Staff see synonyms next to the tags of an appearance, the guide shows them faded',
        ),
    ],
)]
#[OA\Schema(
    schema: 'Sprite',
    type: 'object',
    description: 'Data related to an appearance\'s sprite file. The image itself is `GET /appearances/{id}/sprite?size=300|600&hash={hash}` (the hash is only there for cache busting); `preview`, when sent, is a tiny data URI with the same proportions for laying the page out before the image loads.',
    required: ['hash'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'hash', ref: '#/components/schemas/SpriteHash'),
        new OA\Property(
            property: 'preview',
            type: 'string',
            format: 'data-uri',
            description: 'Data URI for a small preview image with matching proportions to the actual image, suitable for displaying as a preview while the full image loads. May not be sent based on the request parameters.',
            example: 'data:image/png;base64,<image data>',
        ),
    ],
)]
#[OA\Schema(schema: 'SpriteHash', type: 'string', format: 'md5', minLength: 32, maxLength: 32)]
#[OA\Schema(schema: 'SpriteSize', type: 'integer', default: 300, enum: [300, 600])]
#[OA\Schema(
    schema: 'Tag',
    type: 'object',
    description: 'Represents a color guide tag',
    required: ['id', 'name', 'title', 'type', 'uses', 'synonymOf'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'name', type: 'string', description: 'The tag\'s name'),
        new OA\Property(
            property: 'title',
            type: 'string',
            description: 'Optional human-friendly title for the tag',
            nullable: true,
        ),
        new OA\Property(
            property: 'type',
            type: 'string',
            description: 'The tag\'s type/category, null for a plain tag without one',
            nullable: true,
        ),
        new OA\Property(
            property: 'uses',
            type: 'integer',
            description: 'Number of appearances this tag is applied to',
            minimum: 0,
        ),
        new OA\Property(
            property: 'synonymOf',
            description: 'ID of the tag this one is a synonym of, if any',
            nullable: true,
            oneOf: [new OA\Schema(ref: '#/components/schemas/OneBasedId')],
        ),
    ],
)]
#[OA\Schema(
    schema: 'TagListItem',
    type: 'object',
    required: ['id', 'name', 'type', 'title', 'uses', 'synonymOf'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(
            property: 'type',
            type: 'string',
            nullable: true,
            enum: ['app', 'cat', 'gen', 'spec', 'char', 'warn', null],
        ),
        new OA\Property(
            property: 'title',
            type: 'string',
            description: 'Optional description of the tag',
            nullable: true,
        ),
        new OA\Property(property: 'uses', type: 'integer', minimum: 0),
        new OA\Property(
            property: 'synonymOf',
            type: 'object',
            nullable: true,
            required: ['id', 'name'],
            properties: [
                new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'UsefulLink',
    type: 'object',
    required: ['label', 'url', 'title', 'minRole'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'label', type: 'string', minLength: 3, maxLength: 35),
        new OA\Property(property: 'url', type: 'string', format: 'uri', minLength: 3, maxLength: 255),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'minRole', ref: '#/components/schemas/UserRole'),
    ],
)]
#[OA\Schema(
    schema: 'UsefulLinkInput',
    type: 'object',
    description: 'Used to create or update a useful link. \'title\' is optional and defaults to an empty string if omitted',
    required: ['label', 'url', 'minRole'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'label', type: 'string', minLength: 3, maxLength: 35),
        new OA\Property(property: 'url', type: 'string', format: 'uri', minLength: 3, maxLength: 255),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'minRole', ref: '#/components/schemas/UserRole'),
    ],
)]
#[OA\Schema(
    schema: 'User',
    type: 'object',
    description: 'Represents an authenticated user',
    required: ['id', 'name', 'role', 'avatarUrl', 'avatarProvider'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
        new OA\Property(property: 'name', type: 'string', example: 'example'),
        new OA\Property(property: 'role', ref: '#/components/schemas/AccountRole'),
        new OA\Property(
            property: 'avatarUrl',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://a.deviantart.net/avatars/e/x/example.png',
        ),
        new OA\Property(property: 'avatarProvider', ref: '#/components/schemas/AvatarProvider'),
    ],
)]
#[OA\Schema(
    schema: 'UserPrefs',
    type: 'object',
    description: 'The effective preference values (every key is optional when `keys[]` limits the result). The flags are booleans; defaults are in brackets in the descriptions below.',
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'p_avatarprov', ref: '#/components/schemas/AvatarProvider'),
        new OA\Property(
            property: 'cg_itemsperpage',
            type: 'integer',
            description: 'Appearances per page in the color guide [7]',
            minimum: 7,
            maximum: 20,
        ),
        new OA\Property(property: 'cg_hidesynon', type: 'boolean'),
        new OA\Property(property: 'cg_hideclrinfo', type: 'boolean'),
        new OA\Property(property: 'cg_fulllstprev', type: 'boolean'),
        new OA\Property(property: 'cg_nutshell', type: 'boolean'),
        new OA\Property(property: 'p_hidediscord', type: 'boolean'),
        new OA\Property(property: 'p_hidepcg', type: 'boolean'),
        new OA\Property(property: 'p_homelastep', type: 'boolean'),
        new OA\Property(property: 'ep_noappprev', type: 'boolean'),
        new OA\Property(property: 'ep_revstepbtn', type: 'boolean'),
        new OA\Property(property: 'a_pcgearn', type: 'boolean'),
        new OA\Property(property: 'a_pcgmake', type: 'boolean'),
        new OA\Property(property: 'a_pcgsprite', type: 'boolean'),
        new OA\Property(property: 'a_postreq', type: 'boolean'),
        new OA\Property(property: 'a_postres', type: 'boolean'),
        new OA\Property(property: 'a_reserve', type: 'boolean'),
        new OA\Property(
            property: 'p_vectorapp',
            type: 'string',
            description: 'The vector app shown next to the user\'s name, empty for none',
        ),
        new OA\Property(
            property: 'cg_defaultguide',
            type: 'string',
            description: 'Preferred color guide [null]',
            nullable: true,
            enum: ['pony', 'eqg', null],
        ),
        new OA\Property(
            property: 'pcg_slots',
            type: 'integer',
            description: 'Personal guide slots granted by staff [null]',
            nullable: true,
        ),
    ],
)]
#[OA\Schema(
    schema: 'UserProfile',
    type: 'object',
    description: 'Everything the profile page shows about a user, with what the current visitor may do with it',
    required: [
        'user',
        'sameUser',
        'canEdit',
        'devOnDev',
        'editableRoles',
        'discordServerMember',
        'previousUsernames',
        'contributions',
        'contributionsCacheDuration',
        'personalGuides',
        'awaitingApproval',
    ],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
        new OA\Property(
            property: 'deviantArtUrl',
            type: 'string',
            nullable: true,
            description: 'The user\'s DeviantArt profile (not in Winterchilla\'s contract, which renders the profile as HTML)',
        ),
        new OA\Property(
            property: 'vectorApp',
            type: 'string',
            nullable: true,
            description: 'The vector program the user chose to show publicly (preference p_vectorapp)',
        ),
        new OA\Property(
            property: 'discordName',
            type: 'string',
            nullable: true,
            description: 'Name of the user on the club\'s Discord server',
        ),
        new OA\Property(
            property: 'discord',
            type: 'object',
            nullable: true,
            description: 'The linked Discord account, only for the user themselves and staff; null when there is none',
            properties: [
                new OA\Property(
                    property: 'linked',
                    type: 'boolean',
                    description: 'false for accounts that staff bound manually, which no longer count',
                ),
                new OA\Property(property: 'tag', type: 'string'),
                new OA\Property(property: 'serverMember', type: 'boolean'),
                new OA\Property(property: 'lastSynced', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'syncCooldown', type: 'integer', description: 'Seconds between two syncs'),
                new OA\Property(property: 'canSync', type: 'boolean'),
            ],
        ),
        new OA\Property(
            property: 'account',
            type: 'object',
            nullable: true,
            description: 'Sign in details for the Security section of the account page, only for the user themselves and staff',
            properties: [
                new OA\Property(
                    property: 'email',
                    type: 'string',
                    nullable: true,
                    description: 'Only the user themselves and developers get the address, it is null for everybody else (staff included)',
                ),
                new OA\Property(
                    property: 'emailSet',
                    type: 'boolean',
                    description: 'Whether the account has an address at all',
                ),
                new OA\Property(property: 'emailVerifiedAt', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'passwordSet', type: 'boolean'),
            ],
        ),
        new OA\Property(
            property: 'developerInfo',
            type: 'object',
            nullable: true,
            description: 'Only for developers',
            properties: [
                new OA\Property(property: 'deviantArtId', type: 'string', nullable: true),
                new OA\Property(property: 'discordId', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'personalGuideProgress',
            type: 'object',
            nullable: true,
            description: 'Only for the user themselves and staff: whole slots the user has and how many more approved requests give another one',
            properties: [
                new OA\Property(property: 'slots', type: 'integer'),
                new OA\Property(property: 'requestsToNext', type: 'integer'),
            ],
        ),
        new OA\Property(
            property: 'pendingReservations',
            type: 'array',
            nullable: true,
            description: 'Reservations that are not finished yet; only for the user themselves and for staff visiting a member',
            items: new OA\Items(
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/PostItem'),
                    new OA\Schema(
                        type: 'object',
                        required: ['show'],
                        properties: [new OA\Property(property: 'show', ref: '#/components/schemas/ShowListItem')],
                    ),
                ],
            ),
        ),
        new OA\Property(
            property: 'sameUser',
            type: 'boolean',
            description: 'Whether the visitor is looking at their own profile',
        ),
        new OA\Property(
            property: 'canEdit',
            type: 'boolean',
            description: 'Whether the visitor may change this user\'s role',
        ),
        new OA\Property(
            property: 'devOnDev',
            type: 'boolean',
            description: 'Whether a developer is looking at a developer (may change the displayed role label)',
        ),
        new OA\Property(
            property: 'editableRoles',
            type: 'object',
            description: 'Roles the visitor may assign, key to label',
            nullable: true,
            additionalProperties: new OA\AdditionalProperties(type: 'string'),
        ),
        new OA\Property(property: 'discordServerMember', type: 'boolean'),
        new OA\Property(
            property: 'previousUsernames',
            type: 'array',
            description: 'Only sent to the user themselves and to staff',
            nullable: true,
            items: new OA\Items(type: 'string'),
        ),
        new OA\Property(
            property: 'contributions',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['type', 'count', 'noun', 'verb'],
                properties: [
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'count', type: 'integer'),
                    new OA\Property(property: 'noun', type: 'string'),
                    new OA\Property(property: 'verb', type: 'string'),
                ],
            ),
        ),
        new OA\Property(property: 'contributionsCacheDuration', type: 'string', example: 'hour'),
        new OA\Property(
            property: 'personalGuides',
            type: 'array',
            description: 'Null when the user keeps their personal guide section private from the visitor',
            nullable: true,
            items: new OA\Items(
                type: 'object',
                required: ['id', 'label', 'private', 'previewData'],
                properties: [
                    new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
                    new OA\Property(property: 'label', type: 'string'),
                    new OA\Property(property: 'private', type: 'boolean'),
                    new OA\Property(property: 'previewData', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        new OA\Property(
            property: 'awaitingApproval',
            type: 'array',
            description: 'Finished posts waiting for approval; null when the user is not a member',
            nullable: true,
            items: new OA\Items(
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/PostItem'),
                    new OA\Schema(
                        type: 'object',
                        required: ['show'],
                        properties: [new OA\Property(property: 'show', ref: '#/components/schemas/ShowListItem')],
                    ),
                ],
            ),
        ),
    ],
)]
#[OA\Schema(
    schema: 'UserRole',
    type: 'string',
    description: 'List of roles a user can have',
    enum: ['guest', 'user', 'member', 'assistant', 'staff', 'admin', 'developer'],
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    allOf: [
        new OA\Schema(
            type: 'object',
            required: ['errors'],
            properties: [
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    description: 'A map containing error messages for each field that did not pass validation',
                    additionalProperties: new OA\AdditionalProperties(
                        type: 'array',
                        minItems: 1,
                        items: new OA\Items(type: 'string'),
                    ),
                ),
            ],
        ),
        new OA\Schema(ref: '#/components/schemas/ErrorResponse'),
    ],
)]
#[OA\Schema(
    schema: 'ValueOfUser',
    type: 'object',
    description: 'A user\'s data under the user key',
    required: ['user'],
    additionalProperties: false,
    properties: [new OA\Property(property: 'user', ref: '#/components/schemas/User')],
)]
class ContractSchemas
{
}
