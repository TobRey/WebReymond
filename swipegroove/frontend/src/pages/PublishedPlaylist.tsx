import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t, timeAgo, fmtDuration } from '../lib/i18n';
import { toast, user } from '../lib/state';
import { href, navigate } from '../lib/router';
import { now, playTracks } from '../lib/player';
import type { Comment, PublishedPlaylist as PP, UserCard } from '../lib/types';
import { Avatar, Confirm, CoverImg, EmptyState, ErrorState, Loading, Menu, OwnerBadge, Sheet, Spinner, TopBar, UserRow, Field, phClass } from '../components/ui';
import { IExternal, IFlag, IHeart, IHeartFill, IMore, ISend, ITrash, IEdit, ILink, IChat, IX, ISearch } from '../components/Icons';
import { PlayButton, requireLogin } from './Home';
import { artistsText } from '../lib/util';

export function ReportSheet({ type, id, onClose }: { type: 'user' | 'comment' | 'message' | 'playlist'; id: number; onClose: () => void }) {
  const [reason, setReason] = useState('spam');
  const [details, setDetails] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    setBusy(true);
    try { await api('/api/reports', { body: { target_type: type, target_id: id, reason, details } }); toast(t('report.sent'), 'ok'); onClose(); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  return (
    <Sheet title={t('report.title')} onClose={onClose} actions={<><button class="btn" onClick={onClose}>{t('common.cancel')}</button><button class="btn primary" disabled={busy} onClick={submit}>{busy ? <Spinner small /> : t('report.send')}</button></>}>
      <div class="col">
        <Field label={t('report.reason')}>
          <select class="input" value={reason} onChange={(e) => setReason((e.target as HTMLSelectElement).value)}>
            {['spam', 'harassment', 'hate', 'inappropriate', 'impersonation', 'other'].map((r) => <option key={r} value={r}>{t('report.r_' + r)}</option>)}
          </select>
        </Field>
        <Field label={t('report.details')}><textarea class="input" value={details} onInput={(e) => setDetails((e.target as HTMLTextAreaElement).value)} maxLength={1000} /></Field>
      </div>
    </Sheet>
  );
}

export function PublishedPlaylist({ id }: { id: number }) {
  const [pl, setPl] = useState<PP | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [comments, setComments] = useState<Comment[] | null>(null);
  const [cursor, setCursor] = useState<number | null>(null);
  const [body, setBody] = useState('');
  const [editing, setEditing] = useState<Comment | null>(null);
  const [del, setDel] = useState<Comment | null>(null);
  const [report, setReport] = useState<{ type: 'comment' | 'playlist'; id: number } | null>(null);
  const [likers, setLikers] = useState<UserCard[] | null>(null);
  const [share, setShare] = useState(false);
  const [busy, setBusy] = useState(false);
  const me = user.value;
  const load = async () => {
    try { setPl(await api<PP>('/api/community/playlists/' + id)); } catch (e) { if (e instanceof ApiError) setError(e); else showError(e); }
  };
  const loadComments = async (before?: number) => {
    try {
      const r = await api<{ comments: Comment[]; next_cursor: number | null }>('/api/community/playlists/' + id + '/comments', { query: { before } });
      setComments(before ? [...(comments ?? []), ...r.comments] : r.comments);
      setCursor(r.next_cursor);
    } catch { setComments([]); }
  };
  useEffect(() => { load(); loadComments(); }, [id]);
  const toggleLike = async () => {
    if (!pl || !requireLogin()) return;
    try { const r = await api<PP>('/api/community/playlists/' + id + '/like', { method: pl.liked ? 'DELETE' : 'POST', body: {} }); setPl((cur) => ({ ...r, tracks: cur?.tracks })); } catch (e) { showError(e); }
  };
  const send = async (e: Event) => {
    e.preventDefault();
    if (!body.trim()) return;
    setBusy(true);
    try {
      if (editing) {
        const c = await api<Comment>('/api/community/comments/' + editing.id, { method: 'PATCH', body: { body } });
        setComments((cs) => (cs ?? []).map((x) => (x.id === c.id ? c : x)));
        setEditing(null);
      } else {
        const c = await api<Comment>('/api/community/playlists/' + id + '/comments', { body: { body } });
        setComments((cs) => [c, ...(cs ?? [])]);
        setPl((p) => p ? { ...p, comment_count: p.comment_count + 1 } : p);
      }
      setBody('');
    } catch (err) { showError(err); } finally { setBusy(false); }
  };
  const remove = async () => {
    if (!del) return;
    setBusy(true);
    try { await api('/api/community/comments/' + del.id, { method: 'DELETE' }); setComments((cs) => (cs ?? []).filter((x) => x.id !== del.id)); setPl((p) => p ? { ...p, comment_count: Math.max(0, p.comment_count - 1) } : p); setDel(null); } catch (e) { showError(e); } finally { setBusy(false); }
  };
  useEffect(() => {
    if (pl && comments !== null && location.hash === '#comments') document.getElementById('comments')?.scrollIntoView({ block: 'start' });
  }, [pl?.id, comments !== null]);
  if (error) return <Layout><TopBar back="/" /><ErrorState text={error.message} /></Layout>;
  if (!pl) return <Layout><TopBar back="/" /><Loading /></Layout>;
  const ids = (pl.tracks ?? []).map((x) => x.id ?? '');
  const key = 'pl:' + pl.id;
  const playFrom = (i: number) => {
    if (!requireLogin()) return;
    playTracks(key, ids.filter(Boolean), { title: pl.name, subtitle: pl.owner.display_name, image: pl.image_url }, ids.slice(0, i).filter(Boolean).length);
  };
  return (
    <Layout>
      <TopBar back="/" title={pl.name} right={<Menu button={<button class="icon-btn" aria-label={t('common.more')}><IMore /></button>} items={[
        { label: t('common.share'), icon: <ILink />, onClick: () => setShare(true) },
        { label: t('playlist.open_spotify'), icon: <IExternal />, onClick: () => window.open(pl.spotify_url, '_blank', 'noopener') },
        ...(pl.is_owner ? [{ label: t('playlist.manage'), icon: <IEdit />, onClick: () => navigate('/playlists/' + pl.spotify_playlist_id) }] : me ? [{ label: t('report.title'), icon: <IFlag />, danger: true, onClick: () => setReport({ type: 'playlist', id: pl.id }) }] : []),
      ]} />} />
      <div class="post-head">
        <a class="who" href={href('/u/' + pl.owner.username)}>
          <Avatar user={pl.owner} size="sm" />
          <div class="grow"><div class="name truncate">{pl.owner.display_name}{pl.owner.is_owner && <OwnerBadge small />}</div><div class="meta-mono">{t('playlist.n_songs', { n: pl.track_count })} · {timeAgo(pl.published_at)}</div></div>
        </a>
      </div>
      <div class="post-media" style="cursor:default">
        <CoverImg src={pl.image_url} id={pl.id} />
        <div class="post-cap"><div class="t">{pl.name}</div></div>
        <PlayButton p={pl} />
      </div>
      <div class="post-actions">
        <button class={'icon-btn' + (pl.liked ? ' liked' : '')} aria-pressed={pl.liked} aria-label={t('community.like')} onClick={toggleLike}>{pl.liked ? <IHeartFill /> : <IHeart />}</button>
        <button class="count linkish" style="color:var(--text)" onClick={async () => { try { setLikers((await api<{ users: UserCard[] }>('/api/community/playlists/' + id + '/likes')).users); } catch (e) { showError(e); } }}>{pl.like_count}</button>
        <a class="icon-btn" href="#comments" aria-label={t('community.comments')} onClick={(e) => { e.preventDefault(); document.getElementById('comments')?.scrollIntoView({ behavior: 'smooth' }); }}><IChat /></a>
        <span class="count">{pl.comment_count}</span>
        <button class="icon-btn" aria-label={t('common.share')} onClick={() => setShare(true)}><ISend /></button>
      </div>
      {pl.description && <p class="post-caption" style="white-space:pre-wrap"><b>{pl.owner.username}</b> {pl.description}</p>}
      <div class="section-label">{t('playlist.songs')}</div>
      <div class="list">
        {(pl.tracks ?? []).map((tr, i) => {
          const isNow = now.value?.key === key && now.value.title === tr.name;
          return (
            <div key={tr.position} class={'item clickable' + (isNow ? ' selected' : '')} onClick={() => tr.id && playFrom(i)}>
              {tr.image_url ? <img class="thumb" src={tr.image_url} alt="" loading="lazy" /> : <div class={'thumb ' + phClass(tr.id ?? i)} />}
              <div class="meta"><div class="title truncate">{tr.name}</div><div class="sub truncate">{Array.isArray(tr.artists) ? artistsText(tr.artists) : tr.artists}</div></div>
              <span class="meta-mono">{fmtDuration(tr.duration_ms)}</span>
            </div>
          );
        })}
      </div>
      <div class="section-label" id="comments">{t('community.comments')} · {pl.comment_count}</div>
      <div class="list">
        {comments === null ? <Loading /> : comments.length === 0 ? <p class="muted small pad" style="padding-bottom:8px">{t('community.no_comments')}</p> : comments.map((c) => (
          <div key={c.id} class="item" style="align-items:flex-start">
            <a href={href('/u/' + c.author.username)}><Avatar user={c.author} size="sm" /></a>
            <div class="meta">
              <div class="small"><a href={href('/u/' + c.author.username)} style="color:inherit;font-weight:700">{c.author.username}</a> <span style="white-space:pre-wrap">{c.body}</span></div>
              <div class="meta-mono">{timeAgo(c.created_at)}{c.updated_at ? ' · ' + t('common.edited') : ''}</div>
            </div>
            {me && <Menu button={<button class="icon-btn sm" aria-label={t('common.more')}><IMore /></button>} items={[
              ...(c.can_edit ? [{ label: t('common.edit'), icon: <IEdit />, onClick: () => { setEditing(c); setBody(c.body); } }] : []),
              ...(c.can_delete ? [{ label: t('common.delete'), icon: <ITrash />, danger: true, onClick: () => setDel(c) }] : []),
              ...(c.author.id !== me.id ? [{ label: t('report.title'), icon: <IFlag />, danger: true, onClick: () => setReport({ type: 'comment', id: c.id }) }] : []),
            ]} />}
          </div>
        ))}
        {cursor && <div class="row center mt-1"><button class="btn outline sm" onClick={() => loadComments(cursor)}>{t('common.load_more')}</button></div>}
      </div>
      {me ? (
        <form class="composer pad" onSubmit={send}>
          <Avatar user={me} size="sm" />
          <textarea class="input" placeholder={editing ? t('community.edit_comment') : t('community.write_comment')} value={body} onInput={(e) => setBody((e.target as HTMLTextAreaElement).value)} maxLength={1000} rows={1} />
          {editing && <button type="button" class="icon-btn" aria-label={t('common.cancel')} onClick={() => { setEditing(null); setBody(''); }}><IX /></button>}
          <button class="btn primary icon" type="submit" disabled={busy || !body.trim()} aria-label={t('common.send')}>{busy ? <Spinner small /> : <ISend />}</button>
        </form>
      ) : <div class="pad mt-1"><a class="btn outline block" href={href('/login?redirect=' + encodeURIComponent('/community/playlists/' + pl.id))}>{t('guest.login_to_comment')}</a></div>}
      {del && <Confirm title={t('community.delete_comment')} danger busy={busy} onClose={() => setDel(null)} onConfirm={remove} />}
      {report && <ReportSheet type={report.type} id={report.id} onClose={() => setReport(null)} />}
      {likers && <Sheet title={t('community.likes')} onClose={() => setLikers(null)}><div class="list">{likers.length === 0 ? <EmptyState title={t('community.no_public_likes')} /> : likers.map((u) => <UserRow key={u.id} user={u} />)}</div></Sheet>}
      {share && <ShareSheet type="playlist" refId={String(pl.id)} label={pl.name} onClose={() => setShare(false)} />}
    </Layout>
  );
}

/** Teilt einen Link zu Profil/Playlist per Nachricht. */
export function ShareSheet({ type, refId, label, onClose }: { type: 'profile' | 'playlist'; refId: string; label: string; onClose: () => void }) {
  const [q, setQ] = useState('');
  const [users, setUsers] = useState<UserCard[]>([]);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (q.trim().length < 2) { setUsers([]); return; }
    const h = window.setTimeout(() => api<{ users: UserCard[] }>('/api/users/search', { query: { q } }).then((r) => setUsers(r.users)).catch(() => undefined), 300);
    return () => window.clearTimeout(h);
  }, [q]);
  const sendTo = async (u: UserCard) => {
    setBusy(true);
    try {
      const conv = await api<{ id: number }>('/api/conversations', { body: { username: u.username } });
      await api('/api/conversations/' + conv.id + '/messages', { body: { body: label, link: { type, ref: refId } } });
      toast(t('share.sent', { name: u.display_name }), 'ok');
      onClose();
    } catch (e) { showError(e); } finally { setBusy(false); }
  };
  const copy = async () => {
    const link = location.origin + href(type === 'profile' ? '/u/' + refId : '/community/playlists/' + refId);
    try { await navigator.clipboard.writeText(link); toast(t('share.copied'), 'ok'); } catch { toast(link, 'info', 6000); }
  };
  return (
    <Sheet title={t('common.share')} onClose={onClose}>
      <button class="btn outline block mb-2" onClick={copy}><ILink />{t('share.copy_link')}</button>
      {user.value && <>
        <div class="search"><ISearch /><input class="input" placeholder={t('share.send_to')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} /></div>
        <div class="list mt-1">{users.map((u) => <UserRow key={u.id} user={u} right={<button class="btn icon sm primary" aria-label={t('common.send')} disabled={busy} onClick={() => sendTo(u)}><ISend /></button>} />)}</div>
      </>}
    </Sheet>
  );
}
