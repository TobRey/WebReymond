import { Layout } from '../components/Layout';
import { EmptyState } from '../components/ui';
import { t } from '../lib/i18n';
import { href } from '../lib/router';

export function NotFound() {
  return (
    <Layout width="narrow">
      <EmptyState title={t('notfound.title')} text={t('notfound.text')} action={<a class="btn primary" href={href('/')}>{t('notfound.home')}</a>} />
    </Layout>
  );
}
