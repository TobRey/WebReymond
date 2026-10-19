import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t } from '../lib/i18n';
import { setUser, toast, user } from '../lib/state';
import { href, navigate } from '../lib/router';
import type { Me, Profile as ProfileT, PublishedPlaylist, UserCard } from '../lib/types';
import { Avatar, Confirm, CoverImg, Cropper, EmptyState, ErrorState, Field, Loading, Menu, OwnerBadge, Sheet, Spinner, TopBar, UserRow } from '../components/ui';
import { IBlock, IFlag, ILink, ILock, IMore, IMusic, IUsers, IBell, ISettings, IX, ICheck, IGrid, ILibrary, IGlobe, IUserPlus, IUserCheck, IClock } from '../components/Icons';
import { requireLogin } from './Home';
import { LibraryContent } from './Overview';
import { ReportSheet, ShareSheet } from './PublishedPlaylist';
import { readFileAsDataUrl } from '../lib/util';

type PeopleKind = 'followers' | 'following' | 'friends';

export function Profile({ username, self }: { username: string; self: boolean }) {
  const [p, setP] = useState<ProfileT | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [tab, setTab] = useState<'grid' | 'library'>('grid');
  const [lists, setLists] = useState<PublishedPlaylist[] | null>(null);
  const [people, setPeople] = useState<{ kind: PeopleKind; users: UserCard[] | null } | null>(null);
  const [edit, setEdit] = useState(false);
  const [report, setReport] = useState(false);
  const [block, setBlock] = useState(false);
  const [share, setShare] = useState(false);
  const [busy, setBusy] = useState(false);
  const me = user.value;
  const load = async () => {
    setError(null);
    try { setP(await api<ProfileT>('/api/users/' + encodeURIComponent(username))); } catch (e) { if (e instanceof ApiError) setError(e); else showError(e); }
  };
  useEffect(() => { setP(null); load(); setTab('grid'); }, [username]);
  useEffect(() => {
    if (!p || !p.content_visible) return;
    setLists(null);
    api<{ playlists: PublishedPlaylist[] }>('/api/users/' + encodeURIComponent(username) + '/playlists').then((r) => setLists(r.playlists)).catch(() => setLists([]));
  }, [p?.id, p?.content_visible]);
  const openPeople = (kind: PeopleKind) => {
    if (!p?.content_visible && kind !== 'followers') return;
    setPeople({ kind, users: null });
    api<{ users: UserCard[] }>('/api/users/' + encodeURIComponent(username) + '/' + kind).then((r) => setPeople({ kind, users: r.users })).catch(() => setPeople({ kind, users: [] }));
  };
  const rel = async (path: string, method = 'POST') => {
    if (!requireLogin()) return;
    setBusy(true);
    try {
      const r = await api<ProfileT['relationship']>(path, { method, body: {} });
      setP((x) => x ? { ...x, relationship: r, follower_count: x.follower_count + (path.endsWith('/follow') ? (method === 'POST' ? 1 : -1) : 0) } : x);
    } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const message = async () => {
    if (!requireLogin()) return;
    try { const c = await api<{ id: number }>('/api/conversations', { body: { username } }); navigate('/messages/' + c.id); } catch (e) { showError(e); }
  };
  const back = self ? undefined : '/';
  if (error) return <Layout><TopBar back={back ?? '/'} /><ErrorState text={error.message} /></Layout>;
  if (!p) return <Layout><TopBar back={back} /><Loading /></Layout>;
  const r = p.relationship;
  const u = '/api/users/' + encodeURIComponent(username);
  const friendBtn = r && (r.friend_status === 'friends'
    ? <button class="btn icon outline" title={t('profile.friends')} aria-label={t('profile.friends')} disabled={busy} onClick={() => rel(u + '/friend', 'DELETE')}><IUserCheck /></button>
    : r.friend_status === 'requested'
      ? <button class="btn icon outline" title={t('profile.request_sent')} aria-label={t('profile.request_sent')} disabled={busy} onClick={() => rel(u + '/friend-decline')}><IClock /></button>
      : r.friend_status === 'incoming'
        ? <button class="btn success" disabled={busy} onClick={() => rel(u + '/friend-accept')}><ICheck />{t('profile.accept')}</button>
        : <button class="btn icon outline" title={t('profile.add_friend')} aria-label={t('profile.add_friend')} disabled={busy || r.blocked} onClick={() => rel(u + '/friend-request')}><IUserPlus /></button>);
  return (
    <Layout>
      <TopBar back={back} title={'@' + p.username} right={self ? <>
        <a class="icon-btn hide-desktop" href={href('/notifications')} aria-label={t('nav.notifications')}><IBell />{me && me.unread_notifications + me.pending_friend_requests > 0 && <span class="dot" />}</a>
        <a class="icon-btn" href={href('/settings')} aria-label={t('nav.settings')}><ISettings /></a>
      </> : <Menu button={<button class="icon-btn" aria-label={t('common.more')}><IMore /></button>} items={[
        { label: t('common.share'), icon: <ILink />, onClick: () => setShare(true) },
        ...(me ? [{ label: t('report.title'), icon: <IFlag />, onClick: () => setReport(true) },
          r?.blocked ? { label: t('profile.unblock'), icon: <IBlock />, onClick: () => rel(u + '/block', 'DELETE') } : { label: t('profile.block'), icon: <IBlock />, danger: true, onClick: () => setBlock(true) }] : []),
      ]} />} />
      <div class="profile-cover" style={p.banner_url ? { backgroundImage: `url("${p.banner_url}")` } : undefined} />
      <div class="profile-top">
        <Avatar user={p} size="xl" />
        <div class="stats">
          <div><b>{p.published_count ?? '–'}</b><span>{t('profile.posts')}</span></div>
          <button onClick={() => openPeople('followers')}><b>{p.follower_count}</b><span>{t('profile.followers')}</span></button>
          <button onClick={() => openPeople('following')} disabled={!p.content_visible}><b>{p.content_visible ? p.following_count : '–'}</b><span>{t('profile.following')}</span></button>
        </div>
      </div>
      <div class="profile-info">
        <h1>{p.display_name}{p.is_owner && <OwnerBadge small />}{p.is_private && <ILock style="width:15px;height:15px;color:var(--text-3)" aria-label={t('profile.private')} />}</h1>
        <div class="handle">@{p.username}{r?.followed_by ? ' · ' + t('profile.follows_you') : ''}{p.content_visible && p.friend_count ? ' · ' : ''}{p.content_visible && p.friend_count ? <button class="linkish" onClick={() => openPeople('friends')}>{t('profile.n_friends', { n: p.friend_count })}</button> : null}</div>
        {p.content_visible && p.bio && <p class="bio">{p.bio}</p>}
      </div>
      <div class="profile-actions">
        {self ? (<>
          <button class="btn outline" onClick={() => setEdit(true)}>{t('profile.edit')}</button>
          <button class="btn outline" onClick={() => setShare(true)}>{t('common.share')}</button>
        </>) : (<>
          <button class={'btn ' + (r?.following ? 'outline' : 'primary')} disabled={busy || !!r?.blocked} onClick={() => rel(u + '/follow', r?.following ? 'DELETE' : 'POST')}>{r?.following ? t('profile.unfollow') : t('profile.follow')}</button>
          {r?.can_message && <button class="btn outline" onClick={message}>{t('profile.message')}</button>}
          {friendBtn}
          {!me && <button class="btn outline" onClick={() => requireLogin()}>{t('profile.message')}</button>}
        </>)}
      </div>
      {!p.content_visible ? (
        <div class="mt-2"><EmptyState icon={<ILock />} title={t('profile.private_title')} text={t('profile.private_text')} /></div>
      ) : (
        <>
          <div class="tabs mt-1" role="tablist">
            <button role="tab" aria-selected={tab === 'grid'} aria-label={t('profile.playlists')} class={tab === 'grid' ? 'active' : ''} onClick={() => setTab('grid')}><IGrid /></button>
            {self && <button role="tab" aria-selected={tab === 'library'} aria-label={t('nav.library')} class={tab === 'library' ? 'active' : ''} onClick={() => setTab('library')}><ILibrary /></button>}
          </div>
          {tab === 'library' && self ? <LibraryContent embedded /> : lists === null ? <Loading /> : lists.length === 0 ? (
            <EmptyState icon={<IMusic />} title={t('profile.no_playlists')} text={self ? t('profile.no_playlists_self') : undefined} action={self ? <a class="btn primary" href={href('/library?filter=own')}><IGlobe />{t('create.publish')}</a> : undefined} />
          ) : (
            <div class="pgrid">
              {lists.map((pl) => (
                <a key={pl.id} href={href('/community/playlists/' + pl.id)} aria-label={pl.name}>
                  <CoverImg src={pl.image_url} id={pl.id} />
                  <span class="cap truncate">{pl.name}</span>
                </a>
              ))}
            </div>
          )}
        </>
      )}
      {people && (
        <Sheet title={t('profile.' + people.kind)} onClose={() => setPeople(null)}>
          {people.users === null ? <Loading /> : people.users.length === 0 ? <EmptyState icon={<IUsers />} title={t('common.nobody_yet')} /> : <div class="list">{people.users.map((x) => <div key={x.id} onClick={() => setPeople(null)}><UserRow user={x} /></div>)}</div>}
        </Sheet>
      )}
      {edit && <EditProfile onClose={() => setEdit(false)} onSaved={() => { setEdit(false); load(); }} />}
      {report && <ReportSheet type="user" id={p.id} onClose={() => setReport(false)} />}
      {share && <ShareSheet type="profile" refId={p.username} label={'@' + p.username} onClose={() => setShare(false)} />}
      {block && <Confirm title={t('profile.block')} danger text={t('profile.block_text', { name: p.display_name })} busy={busy} onClose={() => setBlock(false)} onConfirm={async () => { await rel(u + '/block'); setBlock(false); toast(t('profile.blocked'), 'ok'); }} />}
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
  const [img, setImg] = useState<{ kind: 'avatar' | 'banner'; src: string; file: File } | null>(null);
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
  const pickFile = async (kind: 'avatar' | 'banner', e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (f) setImg({ kind, file: f, src: await readFileAsDataUrl(f) });
  };
  const aspect = img?.kind === 'avatar' ? 1 : 3;
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
          {(['avatar', 'banner'] as const).map((kind) => (
            <div key={kind} class="row between" style="padding:6px 0;border-bottom:1px solid var(--line)">
              <div class="row gap-1">
                {kind === 'avatar' ? <Avatar user={cur} /> : cur[(kind + '_url') as 'banner_url'] ? <img src={cur[(kind + '_url') as 'banner_url']!} alt="" style="width:64px;height:40px;object-fit:cover;border-radius:6px" /> : <div style="width:64px;height:40px;border-radius:6px;background:var(--surface-2)" />}
                <span class="small">{t('profile.img_' + kind)}</span>
              </div>
              <div class="row gap-1">
                <label class="btn sm outline">{t('common.choose')}<input type="file" accept="image/*" class="sr-only" onChange={(e) => pickFile(kind, e)} /></label>
                {cur[(kind + '_url') as 'avatar_url'] && <button type="button" class="icon-btn sm" aria-label={t('common.remove')} onClick={() => removeImg(kind)}><IX /></button>}
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
