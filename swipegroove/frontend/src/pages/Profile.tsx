import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t } from '../lib/i18n';
import { setUser, toast, user } from '../lib/state';
import { href, navigate } from '../lib/router';
import type { Me, Profile as ProfileT, PublishedPlaylist, UserCard } from '../lib/types';
import { Avatar, Confirm, Cropper, EmptyState, ErrorState, Field, Loading, Menu, OwnerBadge, Sheet, Spinner, Tabs, TopBar, UserRow } from '../components/ui';
import { IBlock, IChat, IEdit, IFlag, ILink, ILock, IMore, IMusic, IUsers, IBell, ISettings, IX, ICheck } from '../components/Icons';
import { PlaylistTile } from './Community';
import { ReportSheet, ShareSheet } from './PublishedPlaylist';
import { readFileAsDataUrl } from '../lib/util';

export function Profile({ username, self }: { username: string; self: boolean }) {
  const [p, setP] = useState<ProfileT | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [tab, setTab] = useState<'playlists' | 'followers' | 'following' | 'friends'>('playlists');
  const [lists, setLists] = useState<PublishedPlaylist[] | null>(null);
  const [people, setPeople] = useState<UserCard[] | null>(null);
  const [edit, setEdit] = useState(false);
  const [report, setReport] = useState(false);
  const [block, setBlock] = useState(false);
  const [share, setShare] = useState(false);
  const [busy, setBusy] = useState(false);
  const me = user.value!;
  const load = async () => {
    setError(null);
    try { setP(await api<ProfileT>('/api/users/' + encodeURIComponent(username))); } catch (e) { if (e instanceof ApiError) setError(e); else showError(e); }
  };
  useEffect(() => { load(); setTab('playlists'); }, [username]);
  useEffect(() => {
    if (!p) return;
    if (tab === 'playlists') { setLists(null); api<{ playlists: PublishedPlaylist[] }>('/api/users/' + username + '/playlists').then((r) => setLists(r.playlists)).catch(() => setLists([])); }
    else { setPeople(null); api<{ users: UserCard[] }>('/api/users/' + username + '/' + tab).then((r) => setPeople(r.users)).catch(() => setPeople([])); }
  }, [tab, p?.id, p?.relationship?.friend_status, p?.relationship?.following]);
  const rel = async (path: string, method = 'POST') => {
    setBusy(true);
    try { const r = await api<ProfileT['relationship']>(path, { method, body: {} }); setP((x) => x ? { ...x, relationship: r } : x); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const message = async () => {
    try { const c = await api<{ id: number }>('/api/conversations', { body: { username } }); navigate('/messages/' + c.id); } catch (e) { showError(e); }
  };
  if (error) return <Layout><TopBar back="/community" /><ErrorState text={error.message} /></Layout>;
  if (!p) return <Layout><TopBar back={self ? undefined : '/community'} /><Loading /></Layout>;
  const r = p.relationship;
  const friendBtn = r && (r.friend_status === 'friends' ? <button class="btn sm" disabled={busy} onClick={() => rel('/api/users/' + username + '/friend', 'DELETE')}><ICheck />{t('profile.friends')}</button>
    : r.friend_status === 'requested' ? <button class="btn sm" disabled={busy} onClick={() => rel('/api/users/' + username + '/friend-decline')}>{t('profile.request_sent')}</button>
    : r.friend_status === 'incoming' ? <span class="row gap-1"><button class="btn sm success" disabled={busy} onClick={() => rel('/api/users/' + username + '/friend-accept')}>{t('profile.accept')}</button><button class="btn sm" disabled={busy} onClick={() => rel('/api/users/' + username + '/friend-decline')}>{t('profile.decline')}</button></span>
    : <button class="btn sm" disabled={busy || r.blocked} onClick={() => rel('/api/users/' + username + '/friend-request')}><IUsers />{t('profile.add_friend')}</button>);
  return (
    <Layout>
      {p.background_url && <div class="profile-bg" style={{ backgroundImage: `url(${p.background_url})` }} aria-hidden="true" />}
      <TopBar back={self ? undefined : '/community'} title={self ? t('nav.profile') : p.display_name} right={self ? <>
        <a class="btn icon ghost rel" href={href('/notifications')} aria-label={t('nav.notifications')}><IBell />{me.unread_notifications > 0 && <span class="badge-dot" style="top:4px;right:4px">{me.unread_notifications}</span>}</a>
        <a class="btn icon ghost" href={href('/settings')} aria-label={t('nav.settings')}><ISettings /></a>
      </> : <Menu button={<button class="btn icon ghost" aria-label={t('common.more')}><IMore /></button>} items={[
        { label: t('common.share'), icon: <ILink />, onClick: () => setShare(true) },
        { label: t('report.title'), icon: <IFlag />, onClick: () => setReport(true) },
        r?.blocked ? { label: t('profile.unblock'), icon: <IBlock />, onClick: () => rel('/api/users/' + username + '/block', 'DELETE') } : { label: t('profile.block'), icon: <IBlock />, danger: true, onClick: () => setBlock(true) },
      ]} />} />
      <div class="banner" style={p.banner_url ? { backgroundImage: `url(${p.banner_url})` } : undefined} />
      <div class="profile-head">
        <Avatar user={p} size="xl" />
        <div class="grow" style="padding-bottom:.4rem">
          <h2 class="row gap-1 wrap">{p.display_name} {p.is_owner && <OwnerBadge />}{p.is_private && <ILock style="width:16px;height:16px;color:var(--text-3)" aria-label={t('profile.private')} />}</h2>
          <div class="muted small">@{p.username}</div>
        </div>
      </div>
      <div class="row wrap gap-2 mt-2" style="padding:0 1rem">
        <span class="small"><strong>{p.follower_count}</strong> <span class="muted">{t('profile.followers')}</span></span>
        {p.content_visible && <span class="small"><strong>{p.following_count}</strong> <span class="muted">{t('profile.following')}</span></span>}
        {p.content_visible && <span class="small"><strong>{p.friend_count}</strong> <span class="muted">{t('profile.friends')}</span></span>}
      </div>
      {p.content_visible && p.bio && <p class="mt-2 small" style="padding:0 1rem;white-space:pre-wrap">{p.bio}</p>}
      <div class="row wrap gap-1 mt-2" style="padding:0 1rem">
        {self ? <button class="btn sm" onClick={() => setEdit(true)}><IEdit />{t('profile.edit')}</button> : r && (<>
          <button class={'btn sm' + (r.following ? '' : ' primary')} disabled={busy || r.blocked} onClick={() => rel('/api/users/' + username + '/follow', r.following ? 'DELETE' : 'POST')}>{r.following ? t('profile.unfollow') : t('profile.follow')}</button>
          {friendBtn}
          {r.can_message && <button class="btn sm" onClick={message}><IChat />{t('profile.message')}</button>}
          {r.followed_by && <span class="chip">{t('profile.follows_you')}</span>}
        </>)}
      </div>
      {!p.content_visible ? (
        <div class="mt-3"><EmptyState icon={<ILock />} title={t('profile.private_title')} text={t('profile.private_text')} /></div>
      ) : (
        <>
          <div class="mt-3"><Tabs value={tab} onChange={setTab} options={[{ value: 'playlists', label: t('profile.playlists') + (p.published_count !== null ? ` (${p.published_count})` : '') }, { value: 'followers', label: t('profile.followers') }, { value: 'following', label: t('profile.following') }, { value: 'friends', label: t('profile.friends') }]} /></div>
          <div class="mt-2">
            {tab === 'playlists' ? (lists === null ? <Loading /> : lists.length === 0 ? <EmptyState icon={<IMusic />} title={t('profile.no_playlists')} text={self ? t('profile.no_playlists_self') : undefined} action={self ? <a class="btn" href={href('/')}>{t('nav.overview')}</a> : undefined} /> : <div class="grid">{lists.map((pl) => <PlaylistTile key={pl.id} p={pl} />)}</div>)
              : (people === null ? <Loading /> : people.length === 0 ? <EmptyState icon={<IUsers />} title={t('common.nobody_yet')} /> : <div class="list">{people.map((u) => <UserRow key={u.id} user={u} />)}</div>)}
          </div>
        </>
      )}
      {edit && <EditProfile onClose={() => setEdit(false)} onSaved={() => { setEdit(false); load(); }} />}
      {report && <ReportSheet type="user" id={p.id} onClose={() => setReport(false)} />}
      {share && <ShareSheet type="profile" refId={p.username} label={'@' + p.username} onClose={() => setShare(false)} />}
      {block && <Confirm title={t('profile.block')} danger text={t('profile.block_text', { name: p.display_name })} busy={busy} onClose={() => setBlock(false)} onConfirm={async () => { await rel('/api/users/' + username + '/block'); setBlock(false); toast(t('profile.blocked'), 'ok'); }} />}
    </Layout>
  );
}

function EditProfile({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const me = user.value!;
  const [display, setDisplay] = useState(me.display_name);
  const [username, setUsername] = useState(me.username);
  const [bio, setBio] = useState(me.bio ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [img, setImg] = useState<{ kind: 'avatar' | 'banner' | 'background'; src: string; file: File } | null>(null);
  const [crop, setCrop] = useState({ x: 0, y: 0, w: 1, h: 1 });
  const save = async (e: Event) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const m = await api<Me>('/api/me', { method: 'PATCH', body: { display_name: display, username, bio } });
      setUser(m);
      toast(t('common.saved'), 'ok');
      if (username !== me.username) navigate('/profile', true);
      onSaved();
    } catch (err) { if (err instanceof ApiError && err.status === 422) setErrors(err.fields); else showError(err); } finally { setBusy(false); }
  };
  const upload = async () => {
    if (!img) return;
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append('image', img.file);
      fd.append('crop', JSON.stringify(crop));
      setUser(await api<Me>('/api/me/image/' + img.kind, { form: fd }));
      setImg(null);
      toast(t('profile.image_saved'), 'ok');
    } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const removeImg = async (kind: string) => {
    try { setUser(await api<Me>('/api/me/image/' + kind, { method: 'DELETE' })); } catch (e) { showError(e); }
  };
  const pickFile = async (kind: 'avatar' | 'banner' | 'background', e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (f) setImg({ kind, file: f, src: await readFileAsDataUrl(f) });
  };
  const aspect = img?.kind === 'avatar' ? 1 : img?.kind === 'banner' ? 3 : 1.6;
  const cur = user.value!;
  return (
    <Sheet title={t('profile.edit')} onClose={onClose} wide>
      {img ? (
        <div class="col">
          <Cropper src={img.src} aspect={aspect} onChange={setCrop} />
          <div class="row gap-1"><button class="btn grow" onClick={() => setImg(null)}>{t('common.cancel')}</button><button class="btn primary grow" disabled={busy} onClick={upload}>{busy ? <Spinner small /> : t('common.save')}</button></div>
        </div>
      ) : (
        <form class="col" onSubmit={save}>
          {(['avatar', 'banner', 'background'] as const).map((kind) => (
            <div key={kind} class="row between surface">
              <div class="row gap-1">
                {kind === 'avatar' ? <Avatar user={cur} /> : cur[(kind + '_url') as 'banner_url'] ? <img src={cur[(kind + '_url') as 'banner_url']!} alt="" style="width:64px;height:40px;object-fit:cover;border-radius:6px" /> : <div style="width:64px;height:40px;border-radius:6px;background:var(--bg-card-2)" />}
                <span class="small">{t('profile.img_' + kind)}</span>
              </div>
              <div class="row gap-1">
                <label class="btn sm">{t('common.choose')}<input type="file" accept="image/*" class="sr-only" onChange={(e) => pickFile(kind, e)} /></label>
                {cur[(kind + '_url') as 'avatar_url'] && <button type="button" class="btn icon ghost sm" aria-label={t('common.remove')} onClick={() => removeImg(kind)}><IX /></button>}
              </div>
            </div>
          ))}
          <Field label={t('profile.display_name')} error={errors.display_name}><input class="input" value={display} onInput={(e) => setDisplay((e.target as HTMLInputElement).value)} maxLength={60} required /></Field>
          <Field label={t('profile.username')} hint={t('profile.username_hint')} error={errors.username}><input class="input" value={username} onInput={(e) => setUsername((e.target as HTMLInputElement).value.toLowerCase())} maxLength={32} required /></Field>
          <Field label={t('profile.bio')} error={errors.bio}><textarea class="input" value={bio} onInput={(e) => setBio((e.target as HTMLTextAreaElement).value)} maxLength={500} /></Field>
          <button class="btn primary block" type="submit" disabled={busy}>{busy ? <Spinner small /> : t('common.save')}</button>
        </form>
      )}
    </Sheet>
  );
}
