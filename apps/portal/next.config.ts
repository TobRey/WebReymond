import createNextIntlPlugin from 'next-intl/plugin';
import type { NextConfig } from 'next';

const withNextIntl = createNextIntlPlugin('./src/i18n/request.ts');

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Verrät die eingesetzte Technik nicht im HTTP-Header.
  poweredByHeader: false,

  async redirects() {
    // "/" hat keine Sprache – wir schicken auf die Standardsprache.
    // Später kann hier eine Erkennung anhand der Browsersprache ergänzt werden.
    return [{ source: '/', destination: '/de', permanent: false }];
  },

  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'X-Frame-Options', value: 'DENY' },
        ],
      },
    ];
  },
};

export default withNextIntl(nextConfig);
