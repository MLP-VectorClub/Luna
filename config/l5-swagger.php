<?php

use App\Enums\AvatarProvider;
use App\Enums\CutieMarkFacing;
use App\Enums\GuideName;
use App\Enums\FullGuideSortField;
use App\Enums\MlpGeneration;
use App\Enums\Role;
use App\Enums\ShowOrdering;
use App\Enums\ShowType;
use App\Enums\SocialProvider;
use App\Enums\SpriteSize;
use App\Enums\TagType;
use App\Enums\UserPrefKey;
use App\Enums\VectorApp;
use App\Utils\Core;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Carbon\Carbon;
use Illuminate\Support\Str;
use const OpenApi\UNDEFINED;

$database_roles = Role::cases();
$dev_role = Role::Developer;
$client_roles = array_filter($database_roles, fn ($role) => $role !== $dev_role->value);
$user_pref_keys = UserPrefKey::cases();

return [
    'default' => 'default',
    'documentations' => [
        'default' => [
            'api' => [
                'title' => 'OpenAPI Documentation - MLP Vector Club',
            ],

            'routes' => [
                /*
                 * Route for accessing api documentation interface
                */
                'api' => '',
            ],
            'paths' => [
                /*
                 * File name of the generated json documentation file
                */
                'docs_json' => 'api-docs.json',

                /*
                 * File name of the generated YAML documentation file
                */
                'docs_yaml' => 'api-docs.yaml',

                /*
                 * Absolute paths to directory containing the swagger annotations are stored.
                */
                'annotations' => [
                    base_path('app'),
                ],

            ],
        ],
    ],
    'defaults' => [
        'routes' => [
            /*
             * Route for accessing parsed swagger annotations.
            */
            'docs' => 'generated',

            /*
             * Route for Oauth2 authentication callback.
            */
            'oauth2_callback' => 'api/oauth2-callback',

            /*
             * Middleware allows to prevent unexpected access to API documentation
            */
            'middleware' => [
                'api' => [],
                'asset' => [],
                'docs' => [],
                'oauth2_callback' => [],
            ],

            /*
             * Route Group options
            */
            'group_options' => [],
        ],

        'paths' => [
            /*
             * Absolute path to location where parsed annotations will be stored
            */
            'docs' => storage_path('api-docs'),

            /*
             * Absolute path to directory where to export views
            */
            'views' => base_path('resources/views/vendor/l5-swagger'),

            /*
             * Edit to set the api's base path
            */
            'base' => env('L5_SWAGGER_BASE_PATH', null),

            /*
             * Edit to set path where swagger ui assets should be stored
            */
            'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),

            /*
             * Absolute path to directories that should be exclude from scanning
            */
            'excludes' => [],
        ],

        /*
         * API security definitions. Will be generated into documentation file.
        */
        'securityDefinitions' => [
            'securitySchemes' => [
                /*
                 * Examples of Security schemes
                */

                'BearerAuth' => [
                    'type' => 'http',
                    'description' => 'Can be used to authenticate using a token sent via HTTP headers',
                    'scheme' => 'bearer',
                ],
                'CookieAuth' => [
                    'type' => 'apiKey',
                    'description' => 'Used for session-based authentication, the cookie is set by the backend on qualifying requests (i.e. browser requests originating from our domain)',
                    'name' => 'mlp_vector_club_session',
                    'in' => 'cookie',
                ],
                /*
                'api_key_security_example' => [ // Unique name of security
                    'type' => 'apiKey', // The type of the security scheme. Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'A short description for security scheme',
                    'name' => 'api_key', // The name of the header or query parameter to be used.
                    'in' => 'header', // The location of the API key. Valid values are "query" or "header".
                ],
                'oauth2_security_example' => [ // Unique name of security
                    'type' => 'oauth2', // The type of the security scheme. Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'A short description for oauth2 security scheme.',
                    'flow' => 'implicit', // The flow used by the OAuth2 security scheme. Valid values are "implicit", "password", "application" or "accessCode".
                    'authorizationUrl' => 'http://example.com/auth', // The authorization URL to be used for (implicit/accessCode)
                    //'tokenUrl' => 'http://example.com/auth' // The authorization URL to be used for (password/application/accessCode)
                    'scopes' => [
                        'read:projects' => 'read your projects',
                        'write:projects' => 'modify projects in your account',
                    ]
                ],
                */

                /* Open API 3.0 support
                'passport' => [ // Unique name of security
                    'type' => 'oauth2', // The type of the security scheme. Valid values are "basic", "apiKey" or "oauth2".
                    'description' => 'Laravel passport oauth2 security.',
                    'in' => 'header',
                    'scheme' => 'https',
                    'flows' => [
                        "password" => [
                            "authorizationUrl" => config('app.url') . '/oauth/authorize',
                            "tokenUrl" => config('app.url') . '/oauth/token',
                            "refreshUrl" => config('app.url') . '/token/refresh',
                            "scopes" => []
                        ],
                    ],
                ],
                */
            ],
            'security' => [
                /*
                 * Examples of Securities
                */
                [
                    /*
                    'oauth2_security_example' => [
                        'read',
                        'write'
                    ],

                    'passport' => []
                    */
                ],
            ],
        ],

        /*
         * Set this to `true` in development mode so that docs would be regenerated on each request
         * Set this to `false` to disable swagger generation on production
        */
        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),

        /*
         * Set this to `true` to generate a copy of documentation in yaml format
        */
        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),

        /*
         * Edit to trust the proxy's ip address - needed for AWS Load Balancer
         * string[]
        */
        'proxy' => false,

        /*
         * Configs plugin allows to fetch external configs instead of passing them to SwaggerUIBundle.
         * See more at: https://github.com/swagger-api/swagger-ui#configs-plugin
        */
        'additional_config_url' => null,

        /*
         * Apply a sort to the operation list of each API. It can be 'alpha' (sort by paths alphanumerically),
         * 'method' (sort by HTTP method).
         * Default is the order returned by the server unchanged.
        */
        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', null),

        /*
         * Pass the validatorUrl parameter to SwaggerUi init on the JS side.
         * A null value here disables validation.
        */
        'validator_url' => null,

        /*
         * Uncomment to add constants which can be used in annotations
         */
        'constants' => [
            'DATABASE_ROLES' => $database_roles,
            'CLIENT_ROLES' => $client_roles,
            'AVATAR_PROVIDERS' => AvatarProvider::cases(),
            'GUIDE_NAMES' => GuideName::cases(),
            'SHOW_TYPES' => ShowType::cases(),
            'SHOW_ORDERING' => ShowOrdering::cases(),
            'MLP_GENERATIONS' => MlpGeneration::cases(),
            'TAG_TYPES' => TagType::cases(),
            'USER_PREF_KEYS' => $user_pref_keys,
            'SPRITE_SIZES' => SpriteSize::cases(),
            'APP_SETTINGS' => array_keys(SettingsHelper::DEFAULT_SETTINGS),
            'SOCIAL_PROVIDERS' => SocialProvider::cases(),
            'VECTOR_APPS' => VectorApp::cases(),
            'ISO_STANDARD_DATE' => Core::carbonToIso(new Carbon()),
            'GUIDE_SORT_FIELDS' => FullGuideSortField::cases(),
            'CUTIE_MARK_FACINGS' => CutieMarkFacing::cases(),
        ],
    ],
];
