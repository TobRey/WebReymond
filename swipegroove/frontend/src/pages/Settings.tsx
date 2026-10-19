import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, showError, url } from '../lib/api';
import { t, lang, setServerBundle, fmtDate, type Lang } from '../lib/i18n';
import { platform, setUser, toast, user } from '../lib/state';
import { href, navigate } from '../lib/router';
import type { Me, UserCard, Playlist } from '../lib/types';
import { Confirm, Field, Notice, Seg, Sheet, Spinner, Toggle, TopBar, UserRow } from '../components/ui';
import { IBlock, IDownload, ILogout, ISpotify, ITrash, IRefresh, ILibrary, IStar, ISparkle, IShield, IChevron } from '../components/Icons';

export function Settings() {
  const me = user.value!;
  const [busy, setBusy] = useState(false);
  const [blocks, setBlocks] = useState<UserCard[] | null>(null);
  const [del, setDel] = useState(false);
  const [confirm, setConfirm] = useState('');
  const [resetSheet, setResetSheet] = useState(false);
  const [disconnect, setDisconnect] = useState(false);
  const [sessions, setSessions] = useState<{ current: boolean; user_agent: string; last_seen_at: string }[] | null>(null);
  const patch = async (body: Record<string, unknown>) => {
    setBusy(true);
    try { setUser(await api<Me>('/api/me', { method: 'PATCH', body })); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const setLang = async (l: Lang) => {
    await patch({ lang: l });
    lang.value = l;
    document.documentElement.lang = l;
    try { const r = await api<{ i18n: Record<string, string> }>('/api/lang', { body: { lang: l } }); setServerBundle(l, r.i18n); } catch { /* ignore */ }
  };
  useEffect(() => { api<{ users: UserCard[] }>('/api/blocks').then((r) => setBlocks(r.users)).catch(() => setBlocks([])); api<{ sessions: typeof sessions }>('/api/me/sessions').then((r) => setSessions(r.sessions)).catch(() => undefined); }, []);
  const logout = async () => { await api('/api/auth/logout', { body: {} }); setUser(null); navigate('/login', true); };
  const logoutAll = async () => { await api('/api/auth/logout-all', { body: {} }); setUser(null); navigate('/login', true); };
  const deleteAccount = async () => {
    setBusy(true);
    try { await api('/api/me', { method: 'DELETE', body: { confirm } }); setUser(null); navigate('/login', true); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const p = platform.value;
  return (
    <Layout width="narrow">
      <TopBar back="/profile" title={t('nav.settings')} />
      <div class="list">
        <a class="item" href={href('/library')}><span class="ai-sm"><ILibrary /></span><div class="meta"><div class="title">{t('nav.library')}</div></div><IChevron style="width:18px;height:18px;color:var(--text-4)" /></a>
        <a class="item" href={href('/premium')}><span class="ai-sm"><IStar /></span><div class="meta"><div class="title">{t('nav.premium')}</div><div class="sub">{me.premium.active ? t('settings.premium_active') : t('settings.premium_inactive')}</div></div><IChevron style="width:18px;height:18px;color:var(--text-4)" /></a>
        <a class="item" href={href('/covers')}><span class="ai-sm"><ISparkle /></span><div class="meta"><div class="title">{t('nav.covers')}</div></div><IChevron style="width:18px;height:18px;color:var(--text-4)" /></a>
        {me.is_admin && <a class="item" href={href('/admin')}><span class="ai-sm"><IShield /></span><div class="meta"><div class="title">{t('nav.admin')}</div></div><IChevron style="width:18px;height:18px;color:var(--text-4)" /></a>}
      </div>
      <div class="col flat-sections">
        <section class="card">
          <h3 class="mb-1">{t('settings.language')}</h3>
          <Seg value={me.lang} onChange={setLang} options={[{ value: 'de', label: 'Deutsch' }, { value: 'en', label: 'English' }]} />
        </section>
        <section class="card" style="padding-top:.4rem;padding-bottom:.4rem">
          <h3 class="mt-2 mb-1">{t('settings.privacy')}</h3>
          <Toggle label={t('settings.private_profile')} desc={t('settings.private_profile_hint')} checked={me.is_private} disabled={busy} onChange={(v) => patch({ is_private: v })} />
          <Toggle label={t('settings.msg_friends')} desc={t('settings.msg_friends_hint')} checked={me.msg_from_friends} disabled={busy} onChange={(v) => patch({ msg_from_friends: v })} />
          <Toggle label={t('settings.msg_requests')} desc={t('settings.msg_requests_hint')} checked={me.msg_requests_allowed} disabled={busy} onChange={(v) => patch({ msg_requests_allowed: v })} />
        </section>
        <section class="card">
          <h3 class="mb-1">{t('settings.spotify')}</h3>
          <div class="small muted mb-1">{t('settings.spotify_status')}: <strong style="color:var(--text)">{t('spotify.status_' + me.spotify.status)}</strong>{me.spotify.display_name ? ' · ' + me.spotify.display_name : ''}</div>
          {me.spotify.reauth_due_at && <div class="tiny muted mb-1">{t('settings.reauth_due', { date: fmtDate(me.spotify.reauth_due_at) })}</div>}
          <div class="row wrap gap-1">
            <a class="btn sm" href={href('/auth/spotify/start?purpose=reconnect')}><ISpotify />{t('spotify.reconnect')}</a>
            {me.spotify.status === 'connected' && <button class="btn sm ghost" onClick={() => setDisconnect(true)}>{t('settings.disconnect')}</button>}
          </div>
          <p class="tiny muted mt-1">{t('settings.scopes_note')}</p>
        </section>
        <section class="card" style="padding-top:.4rem;padding-bottom:.4rem">
          <h3 class="mt-2 mb-1">{t('settings.playback')}</h3>
          {p.playback_mode === 'off' ? <p class="small muted" style="padding-bottom:.6rem">{t('settings.playback_off_platform')}</p>
            : <Toggle label={t('settings.playback_enable')} desc={t('settings.playback_hint')} checked={me.playback_enabled} disabled={busy} onChange={(v) => patch({ playback_enabled: v })} />}
        </section>
        <section class="card">
          <h3 class="mb-1">{t('settings.swipe')}</h3>
          <p class="small muted mb-1">{t('settings.reset_hidden_hint')}</p>
          <button class="btn sm" onClick={() => setResetSheet(true)}><IRefresh />{t('settings.reset_hidden')}</button>
        </section>
        <section class="card">
          <h3 class="mb-1">{t('settings.blocked')}</h3>
          {blocks === null ? <Spinner small /> : blocks.length === 0 ? <p class="small muted">{t('settings.no_blocked')}</p> : <div class="list">{blocks.map((u) => <UserRow key={u.id} user={u} right={<button class="btn sm" onClick={async () => { await api('/api/users/' + u.username + '/block', { method: 'DELETE' }); setBlocks(blocks.filter((x) => x.id !== u.id)); }}><IBlock />{t('profile.unblock')}</button>} />)}</div>}
        </section>
        <section class="card">
          <h3 class="mb-1">{t('settings.sessions')}</h3>
          {sessions && <div class="small muted mb-1">{t('settings.sessions_count', { n: sessions.length })}</div>}
          <div class="row wrap gap-1">
            <button class="btn sm" onClick={logout}><ILogout />{t('settings.logout')}</button>
            <button class="btn sm ghost" onClick={logoutAll}>{t('settings.logout_all')}</button>
          </div>
        </section>
        <section class="card">
          <h3 class="mb-1">{t('settings.data')}</h3>
          <div class="row wrap gap-1">
            <a class="btn sm" href={url('/api/me/export')} download><IDownload />{t('settings.export')}</a>
            {!me.is_owner && <button class="btn sm danger" onClick={() => setDel(true)}><ITrash />{t('settings.delete_account')}</button>}
          </div>
          {me.is_owner && <p class="tiny muted mt-1">{t('settings.owner_no_delete')}</p>}
        </section>
        <div class="legal-links">
          <a href={href('/legal/privacy')}>{t('legal.privacy')}</a><a href={href('/legal/terms')}>{t('legal.terms')}</a><a href={href('/legal/imprint')}>{t('legal.imprint')}</a>
        </div>
      </div>
      {resetSheet && <ResetHidden onClose={() => setResetSheet(false)} />}
      {disconnect && <Confirm title={t('settings.disconnect')} danger text={t('settings.disconnect_text')} busy={busy} onClose={() => setDisconnect(false)} onConfirm={async () => { setBusy(true); try { setUser(await api<Me>('/api/me/spotify/disconnect', { body: {} })); setDisconnect(false); } catch (e) { showError(e); } finally { setBusy(false); } }} />}
      {del && <Sheet title={t('settings.delete_account')} onClose={() => setDel(false)} actions={<><button class="btn" onClick={() => setDel(false)}>{t('common.cancel')}</button><button class="btn danger" disabled={busy || confirm !== me.username} onClick={deleteAccount}>{busy ? <Spinner small /> : t('settings.delete_confirm')}</button></>}>
        <Notice kind="danger">{t('settings.delete_text')}</Notice>
        <div class="mt-2"><Field label={t('settings.delete_type_username', { name: me.username })}><input class="input" value={confirm} onInput={(e) => setConfirm((e.target as HTMLInputElement).value)} autocomplete="off" /></Field></div>
      </Sheet>}
    </Layout>
  );
}

function ResetHidden({ onClose }: { onClose: () => void }) {
  const [lists, setLists] = useState<Playlist[] | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => { api<{ targets: Playlist[] }>('/api/swipe/targets').then((r) => setLists(r.targets)).catch(() => setLists([])); }, []);
  const reset = async (id?: string) => {
    setBusy(true);
    try { const r = await api<{ reset: number }>('/api/me/swipes/reset', { body: { playlist_id: id } }); toast(t('settings.reset_done', { n: r.reset }), 'ok'); onClose(); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  return (
    <Sheet title={t('settings.reset_hidden')} onClose={onClose}>
      <p class="small muted mb-2">{t('settings.reset_hidden_text')}</p>
      <button class="btn block mb-2 danger" disabled={busy} onClick={() => reset()}>{t('settings.reset_all')}</button>
      {lists === null ? <Spinner small /> : <div class="list">{lists.map((p) => <button key={p.id} class="item clickable" style="text-align:left;width:100%" disabled={busy} onClick={() => reset(p.id)}>{p.image_url ? <img class="thumb" src={p.image_url} alt="" /> : <div class="thumb" />}<div class="meta"><div class="title truncate">{p.name}</div></div></button>)}</div>}
    </Sheet>
  );
}
