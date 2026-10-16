/**
 * Used by App\Utils\Media\ProjectSvgo (see config/media-library.php). svgo 2 and newer have no --disable flag, plugins are
 * configured here instead.
 */
export default {
  plugins: [
    {
      name: 'preset-default',
      params: {
        overrides: {
          // Disabled because it is known to cause troubles
          cleanupIds: false,
        },
      },
    },
  ],
};
