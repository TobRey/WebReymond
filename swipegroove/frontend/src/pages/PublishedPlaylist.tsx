import { useEffect, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t, timeAgo, fmtDuration } from '../lib/i18n';
import { toast, user } from '../lib/state';
import { href } from '../lib/router';
import type { Comment, PublishedPlaylist as PP, UserCard } from '../lib/types';
import { Avatar, Confirm, EmptyState, ErrorState, Loading, Menu, OwnerBadge, Sheet, Spinner, TopBar, UserRow, Field } from '../components/ui';
import { IExternal, IFlag, IHeart, IHeartFill, IMore, IMusic, ISend, ITrash, IEdit, ILink } from '../components/Icons';
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
  const me = user.value!;
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
    if (!pl) return;
    try { setPl(await api<PP>('/api/community/playlists/' + id + '/like', { method: pl.liked ? 'DELETE' : 'POST', body: {} })); } catch (e) { showError(e); }
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
  if (error) return <Layout><TopBar back="/community" /><ErrorState text={error.message} /></Layout>;
  if (!pl) return <Layout><TopBar back="/community" /><Loading /></Layout>;
  return (
    <Layout>
      <TopBar back="/community" title={pl.name} right={<Menu button={<button class="btn icon ghost" aria-label={t('common.more')}><IMore /></button>} items={[
        { label: t('common.share'), icon: <ILink />, onClick: () => setShare(true) },
        { label: t('playlist.open_spotify'), icon: <IExternal />, onClick: () => window.open(pl.spotify_url, '_blank', 'noopener') },
        ...(pl.is_owner ? [{ label: t('playlist.manage'), icon: <IEdit />, onClick: () => { location.href = href('/playlists/' + pl.spotify_playlist_id); } }] : [{ label: t('report.title'), icon: <IFlag />, danger: true, onClick: () => setReport({ type: 'playlist', id: pl.id }) }]),
      ]} />} />
      <div class="card row gap-2" style="align-items:flex-start">
        {pl.image_url ? <img src={pl.image_url} alt="" style="width:110px;height:110px;border-radius:12px;object-fit:cover" /> : <div style="width:110px;height:110px;border-radius:12px;background:var(--bg-card-2);display:grid;place-items:center"><IMusic /></div>}
        <div class="grow col gap-1">
          <h2>{pl.name}</h2>
          {pl.description && <p class="muted small">{pl.description}</p>}
          <a href={href('/u/' + pl.owner.username)} class="row gap-1 small" style="color:inherit"><Avatar user={pl.owner} size="sm" /><span>{pl.owner.display_name}</span>{pl.owner.is_owner && <OwnerBadge small />}</a>
          <div class="small muted">{t('playlist.n_songs', { n: pl.track_count })} · {t('community.published_ago', { time: timeAgo(pl.published_at) })}</div>
          <div class="row gap-1 wrap">
            <button class={'btn sm' + (pl.liked ? ' success' : '')} onClick={toggleLike} aria-pressed={pl.liked}>{pl.liked ? <IHeartFill /> : <IHeart />}{pl.like_count}</button>
            <button class="btn sm ghost" onClick={async () => { try { setLikers((await api<{ users: UserCard[] }>('/api/community/playlists/' + id + '/likes')).users); } catch (e) { showError(e); } }}>{t('community.show_likes')}</button>
            <a class="btn sm" href={pl.spotify_url} target="_blank" rel="noopener"><IExternal />{t('track.listen_spotify')}</a>
          </div>
          <p class="tiny muted">{t('community.likes_note')}</p>
        </div>
      </div>
      <div class="list mt-2">
        {(pl.tracks ?? []).map((tr) => (
          <div key={tr.position} class="item">
            {tr.image_url ? <img class="thumb" src={tr.image_url} alt="" loading="lazy" /> : <div class="thumb" />}
            <div class="meta"><div class="title truncate">{tr.name}</div><div class="sub truncate">{Array.isArray(tr.artists) ? artistsText(tr.artists) : tr.artists}{tr.album_name ? ' · ' + tr.album_name : ''}</div></div>
            <span class="tiny muted">{fmtDuration(tr.duration_ms)}</span>
            {tr.spotify_url && <a class="btn icon ghost sm" href={tr.spotify_url} target="_blank" rel="noopener" aria-label={t('track.open_spotify')}><IExternal /></a>}
          </div>
        ))}
      </div>
      <h3 class="mt-3 mb-1">{t('community.comments')} ({pl.comment_count})</h3>
      <form class="composer" onSubmit={send}>
        <textarea class="input" placeholder={editing ? t('community.edit_comment') : t('community.write_comment')} value={body} onInput={(e) => setBody((e.target as HTMLTextAreaElement).value)} maxLength={1000} rows={2} />
        {editing && <button type="button" class="btn ghost sm" onClick={() => { setEditing(null); setBody(''); }}>{t('common.cancel')}</button>}
        <button class="btn primary icon" type="submit" disabled={busy || !body.trim()} aria-label={t('common.send')}>{busy ? <Spinner small /> : <ISend />}</button>
      </form>
      <div class="list mt-2">
        {comments === null ? <Loading /> : comments.length === 0 ? <p class="muted small" style="text-align:center;padding:1rem">{t('community.no_comments')}</p> : comments.map((c) => (
          <div key={c.id} class="card flat">
            <div class="row small">
              <a href={href('/u/' + c.author.username)} class="row gap-1" style="color:inherit;text-decoration:none"><Avatar user={c.author} size="sm" /><strong>{c.author.display_name}</strong></a>
              <span class="muted tiny" style="margin-left:auto">{timeAgo(c.created_at)}{c.updated_at ? ' · ' + t('common.edited') : ''}</span>
              <Menu button={<button class="btn icon ghost sm" aria-label={t('common.more')}><IMore /></button>} items={[
                ...(c.can_edit ? [{ label: t('common.edit'), icon: <IEdit />, onClick: () => { setEditing(c); setBody(c.body); } }] : []),
                ...(c.can_delete ? [{ label: t('common.delete'), icon: <ITrash />, danger: true, onClick: () => setDel(c) }] : []),
                ...(c.author.id !== me.id ? [{ label: t('report.title'), icon: <IFlag />, danger: true, onClick: () => setReport({ type: 'comment', id: c.id }) }] : []),
              ]} />
            </div>
            <p class="mt-1" style="white-space:pre-wrap">{c.body}</p>
          </div>
        ))}
        {cursor && <button class="btn" onClick={() => loadComments(cursor)}>{t('common.load_more')}</button>}
      </div>
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
      <button class="btn block mb-2" onClick={copy}><ILink />{t('share.copy_link')}</button>
      <p class="small muted mb-1">{t('share.send_to')}</p>
      <input class="input" placeholder={t('community.search_users')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} />
      <div class="list mt-2">{users.map((u) => <UserRow key={u.id} user={u} right={<button class="btn sm primary" disabled={busy} onClick={() => sendTo(u)}><ISend /></button>} />)}</div>
    </Sheet>
  );
}
