import { useEffect, useRef, useState } from 'preact/hooks';
import { Layout } from '../components/Layout';
import { api, ApiError, showError } from '../lib/api';
import { t, timeAgo, fmtDate } from '../lib/i18n';
import { setUser, toast, user } from '../lib/state';
import { href, navigate } from '../lib/router';
import type { Conversation as ConvT, Message, UserCard } from '../lib/types';
import { Avatar, EmptyState, ErrorState, Loading, Menu, Sheet, Spinner, TopBar, UserRow, Notice } from '../components/ui';
import { IChat, IFlag, IMore, IPlus, ISend, IUser, IMusic, ISearch } from '../components/Icons';
import { ReportSheet } from './PublishedPlaylist';

export function Messages() {
  const [convs, setConvs] = useState<ConvT[] | null>(null);
  const [start, setStart] = useState(false);
  const load = async () => { try { setConvs((await api<{ conversations: ConvT[] }>('/api/conversations')).conversations); } catch (e) { showError(e); setConvs([]); } };
  useEffect(() => { load(); const h = window.setInterval(() => { if (document.visibilityState === 'visible') load(); }, 20000); return () => window.clearInterval(h); }, []);
  return (
    <Layout width="narrow">
      <TopBar title={t('messages.title')} right={<button class="btn icon primary" aria-label={t('messages.new')} onClick={() => setStart(true)}><IPlus /></button>} />
      <p class="tiny muted mb-2">{t('messages.e2e_note')}</p>
      {convs === null ? <Loading /> : convs.length === 0 ? <EmptyState icon={<IChat />} title={t('messages.empty_title')} text={t('messages.empty_text')} action={<button class="btn primary" onClick={() => setStart(true)}>{t('messages.new')}</button>} /> : (
        <div class="list">
          {convs.map((c) => (
            <a key={c.id} class="item clickable" href={href('/messages/' + c.id)} style="color:inherit;text-decoration:none">
              <Avatar user={c.other} />
              <div class="meta">
                <div class="title row between"><span class="truncate">{c.other.display_name}</span>{c.last_message_at && <span class="tiny muted" style="font-weight:400">{timeAgo(c.last_message_at)}</span>}</div>
                <div class="sub truncate">{c.status === 'pending' ? (c.is_initiator ? t('messages.request_pending_short') : t('messages.request_incoming')) : c.status === 'declined' ? t('messages.declined_short') : c.last_message ? (c.last_message.mine ? t('messages.you') + ': ' : '') + (c.last_message.deleted ? t('messages.deleted') : c.last_message.link ? '🔗 ' + c.last_message.body : c.last_message.body) : ''}</div>
              </div>
              {c.unread_count > 0 && <span class="badge-dot" style="position:static">{c.unread_count}</span>}
            </a>
          ))}
        </div>
      )}
      {start && <StartSheet onClose={() => setStart(false)} />}
    </Layout>
  );
}

function StartSheet({ onClose }: { onClose: () => void }) {
  const [q, setQ] = useState('');
  const [users, setUsers] = useState<UserCard[]>([]);
  useEffect(() => {
    if (q.trim().length < 2) { setUsers([]); return; }
    const h = window.setTimeout(() => api<{ users: UserCard[] }>('/api/users/search', { query: { q } }).then((r) => setUsers(r.users)).catch(() => undefined), 300);
    return () => window.clearTimeout(h);
  }, [q]);
  const start = async (u: UserCard) => {
    try { const c = await api<{ id: number }>('/api/conversations', { body: { username: u.username } }); navigate('/messages/' + c.id); } catch (e) { showError(e); }
  };
  return (
    <Sheet title={t('messages.new')} onClose={onClose}>
      <div class="search"><ISearch /><input class="input" placeholder={t('community.search_users')} value={q} onInput={(e) => setQ((e.target as HTMLInputElement).value)} autoFocus /></div>
      <p class="tiny muted mt-1">{t('messages.request_note')}</p>
      <div class="list mt-2">{users.map((u) => <UserRow key={u.id} user={u} right={<button class="btn sm primary" onClick={() => start(u)}><IChat /></button>} />)}</div>
    </Sheet>
  );
}

export function Conversation({ id }: { id: number }) {
  const [conv, setConv] = useState<ConvT | null>(null);
  const [msgs, setMsgs] = useState<Message[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [report, setReport] = useState<number | null>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const lastId = useRef(0);
  const me = user.value!;
  const scrollBottom = () => { const el = listRef.current; if (el) el.scrollTop = el.scrollHeight; };
  const init = async () => {
    try {
      const all = await api<{ conversations: ConvT[] }>('/api/conversations');
      const c = all.conversations.find((x) => x.id === id) ?? null;
      if (!c) throw new ApiError(404, 'messages.not_found', t('messages.not_found'));
      setConv(c);
      const r = await api<{ messages: Message[]; has_more: boolean }>('/api/conversations/' + id + '/messages');
      setMsgs(r.messages);
      setHasMore(r.has_more);
      lastId.current = r.messages.length ? r.messages[r.messages.length - 1].id : 0;
      await api('/api/conversations/' + id + '/read', { body: {} });
      setUser({ ...me, unread_messages: Math.max(0, me.unread_messages - c.unread_count) });
      setTimeout(scrollBottom, 30);
    } catch (e) { if (e instanceof ApiError) setError(e); else showError(e); }
  };
  useEffect(() => { init(); }, [id]);
  // Inkrementelles Polling nur für neue Nachrichten ab der letzten ID
  useEffect(() => {
    if (!conv) return;
    let stop = false;
    const tick = async () => {
      if (stop || document.visibilityState !== 'visible') return;
      try {
        const u = await api<{ messages: Message[]; conversation_status: string }>('/api/updates', { query: { conversation: id, after: lastId.current } });
        if (u.messages.length) {
          setMsgs((m) => [...m, ...u.messages.filter((x) => !m.some((y) => y.id === x.id))]);
          lastId.current = u.messages[u.messages.length - 1].id;
          await api('/api/conversations/' + id + '/read', { body: {} });
          setTimeout(scrollBottom, 30);
        }
        if (u.conversation_status && u.conversation_status !== conv.status) setConv({ ...conv, status: u.conversation_status as ConvT['status'] });
      } catch { /* leise */ }
    };
    const h = window.setInterval(tick, 4000);
    return () => { stop = true; window.clearInterval(h); };
  }, [conv?.id, conv?.status]);
  const loadOlder = async () => {
    const first = msgs[0]?.id;
    if (!first) return;
    try { const r = await api<{ messages: Message[]; has_more: boolean }>('/api/conversations/' + id + '/messages', { query: { before: first } }); setMsgs([...r.messages, ...msgs]); setHasMore(r.has_more); } catch (e) { showError(e); }
  };
  const send = async (e?: Event) => {
    e?.preventDefault();
    if (!body.trim() || busy) return;
    setBusy(true);
    try {
      const m = await api<Message>('/api/conversations/' + id + '/messages', { body: { body } });
      setMsgs((x) => [...x, m]);
      lastId.current = m.id;
      setBody('');
      setTimeout(scrollBottom, 30);
    } catch (err) { showError(err); } finally { setBusy(false); }
  };
  const respond = async (accept: boolean) => {
    try { setConv(await api<ConvT>('/api/conversations/' + id + (accept ? '/accept' : '/decline'), { body: {} })); } catch (e) { showError(e); }
  };
  if (error) return <Layout width="narrow"><TopBar back="/messages" /><ErrorState text={error.message} /></Layout>;
  if (!conv) return <Layout width="narrow"><TopBar back="/messages" /><Loading /></Layout>;
  const canSend = conv.status === 'accepted' || (conv.status === 'pending' && conv.is_initiator && msgs.length === 0);
  return (
    <Layout width="narrow">
      <TopBar back="/messages" right={<Menu button={<button class="btn icon ghost" aria-label={t('common.more')}><IMore /></button>} items={[{ label: t('profile.view'), icon: <IUser />, onClick: () => navigate('/u/' + conv.other.username) }]} />}>
        <a href={href('/u/' + conv.other.username)} class="row gap-1 grow" style="color:inherit;text-decoration:none;min-width:0"><Avatar user={conv.other} size="sm" /><strong class="truncate">{conv.other.display_name}</strong></a>
      </TopBar>
      <div class="chat">
        {conv.status === 'pending' && !conv.is_initiator && <Notice kind="info"><div>{t('messages.request_text', { name: conv.other.display_name })}</div><div class="row gap-1 mt-1"><button class="btn sm success" onClick={() => respond(true)}>{t('profile.accept')}</button><button class="btn sm" onClick={() => respond(false)}>{t('profile.decline')}</button></div></Notice>}
        {conv.status === 'pending' && conv.is_initiator && msgs.length > 0 && <Notice kind="info">{t('messages.request_pending')}</Notice>}
        {conv.status === 'declined' && <Notice kind="warn">{t('messages.declined')}</Notice>}
        <div class="messages" ref={listRef}>
          {hasMore && <button class="btn sm ghost" onClick={loadOlder}>{t('common.load_more')}</button>}
          {msgs.map((m) => (
            <div key={m.id} class={'bubble' + (m.mine ? ' mine' : '') + (m.deleted ? ' deleted' : '')} onContextMenu={(e) => { if (!m.mine && !m.deleted) { e.preventDefault(); setReport(m.id); } }}>
              {m.deleted ? t('messages.deleted') : (<>
                {m.link
                  ? <a class="btn sm block" href={href(m.link.type === 'profile' ? '/u/' + m.link.ref : '/community/playlists/' + m.link.ref)}>{m.link.type === 'profile' ? <IUser /> : <IMusic />}{m.body || (m.link.type === 'profile' ? '@' + m.link.ref : t('messages.playlist_link'))}</a>
                  : m.body}
              </>)}
              <span class="time">{fmtDate(m.created_at, true)}{!m.mine && !m.deleted && <button class="btn ghost sm" style="min-height:0;padding:0 .3rem;font-size:.65rem" onClick={() => setReport(m.id)}><IFlag style="width:11px;height:11px" />{t('report.short')}</button>}</span>
            </div>
          ))}
        </div>
        {canSend ? (
          <form class="composer" onSubmit={send}>
            <textarea class="input" rows={1} placeholder={t('messages.write')} value={body} maxLength={2000} onInput={(e) => setBody((e.target as HTMLTextAreaElement).value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }} />
            <button class="btn primary icon" type="submit" disabled={busy || !body.trim()} aria-label={t('common.send')}>{busy ? <Spinner small /> : <ISend />}</button>
          </form>
        ) : conv.status === 'accepted' ? null : <p class="tiny muted" style="text-align:center;padding:.5rem">{conv.status === 'declined' ? t('messages.declined') : t('messages.request_pending')}</p>}
      </div>
      {report && <ReportSheet type="message" id={report} onClose={() => setReport(null)} />}
    </Layout>
  );
}
