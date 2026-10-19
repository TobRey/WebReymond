import { href } from '../lib/router';
import { t } from '../lib/i18n';
import type { PublishedPlaylist } from '../lib/types';
import { CoverImg } from '../components/ui';
import { IHeart } from '../components/Icons';
import { PlayButton } from './Home';

/** Kachel einer veröffentlichten Playlist (Suche, Profil). */
export function PlaylistTile({ p, showOwner = true }: { p: PublishedPlaylist; showOwner?: boolean }) {
  return (
    <a class="tile" href={href('/community/playlists/' + p.id)}>
      <div class="art">
        <CoverImg src={p.image_url} id={p.id} />
        <PlayButton p={p} />
      </div>
      <div class="name truncate">{p.name}</div>
      <div class="sub"><span class="truncate">{showOwner ? '@' + p.owner.username : t('playlist.n_songs', { n: p.track_count })}</span><IHeart />{p.like_count}</div>
    </a>
  );
}
