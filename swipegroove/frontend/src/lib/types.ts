export interface Boot {
  basePath: string;
  baseUrl: string;
  lang: 'de' | 'en';
  csrf: string | null;
  version: string;
  user: Me | null;
  platform: Platform;
  i18n: Record<string, string>;
}
export interface Platform {
  owner_registered: boolean;
  registration_open: boolean;
  operation_mode: string;
  spotify_configured: boolean;
  playback_mode: 'off' | 'connect';
  covers_available: boolean;
  cover_model: string;
  billing_available: boolean;
  billing_mode: string;
  premium_price_chf: string;
  premium_cover_quota: number;
  legal: { operator_name: string; operator_address: string; operator_email: string; operator_country: string };
}
export interface Me {
  id: number; username: string; display_name: string; bio: string | null;
  avatar_url: string | null; banner_url: string | null; background_url: string | null;
  is_private: boolean; lang: 'de' | 'en'; role: string; is_admin: boolean; is_owner: boolean; status: string;
  onboarding_done: boolean; msg_from_friends: boolean; msg_requests_allowed: boolean; playback_enabled: boolean;
  premium: { active: boolean; source: string; unlimited_covers: boolean; status: string; cancel_at_period_end: boolean; current_period_end: string | null; manual_until: string | null; payment_error: string | null };
  spotify: { status: string; scopes: string[]; authorized_at: string | null; reauth_due_at: string | null; display_name?: string | null; last_error?: string | null };
  unread_messages: number; unread_notifications: number; pending_friend_requests: number; created_at: string;
}
export interface Playlist {
  id: string; name: string; description: string | null; image_url: string | null; owner_name: string | null;
  is_owner: boolean; is_collaborative: boolean; can_edit: boolean; spotify_public: boolean | null; track_count: number;
  snapshot_id: string | null; is_published: boolean; published_id: number | null; like_count: number; comment_count: number;
  synced_at: string; items_loaded: boolean; spotify_url: string; verified_empty?: boolean;
}
export interface Track {
  position?: number; id: string | null; uri: string | null; name: string | null; artists: string[]; artist_ids?: string[];
  album_name: string | null; album_id?: string | null; image_url: string | null; duration_ms: number | null; is_local?: boolean;
  added_at?: string | null; spotify_url?: string | null;
}
export interface Card { id: string; uri: string; name: string; artists: string[]; album_name: string | null; image_url: string | null; duration_ms: number | null; source: string | null; spotify_url: string | null }
export interface UserCard { id: number; username: string; display_name: string; avatar_url: string | null; is_private: boolean; is_owner: boolean }
export interface Profile extends UserCard {
  is_admin: boolean; follower_count: number; following_count?: number; friend_count?: number; bio?: string | null; banner_url?: string | null; background_url?: string | null;
  created_at?: string; content_visible: boolean; published_count: number | null;
  relationship: { following: boolean; followed_by: boolean; friend_status: 'none' | 'requested' | 'incoming' | 'friends'; blocked: boolean; can_message: boolean } | null;
}
export interface PublishedPlaylist {
  id: number; spotify_playlist_id: string; name: string; description: string | null; image_url: string | null; track_count: number; like_count: number; comment_count: number;
  published_at: string | null; updated_at: string; spotify_url: string; owner: UserCard; liked: boolean; is_owner: boolean; tracks?: Track[];
}
export interface Comment { id: number; body: string; author: UserCard; created_at: string; updated_at: string | null; can_edit: boolean; can_delete: boolean }
export interface Conversation { id: number; status: 'pending' | 'accepted' | 'declined'; is_initiator: boolean; other: UserCard; unread_count: number; last_message: Message | null; last_message_at: string | null; created_at: string }
export interface Message { id: number; conversation_id: number; sender_id: number; mine: boolean; body: string; link: { type: string; ref: string } | null; deleted: boolean; created_at: string }
export interface Notification { id: number; type: string; actor: UserCard | null; ref_type: string | null; ref_id: number | null; data: Record<string, string> | null; read: boolean; created_at: string }
export interface Operation { id: string; type: string; status: string; source_playlist_id: string | null; target_playlist_id: string | null; progress: Record<string, unknown>; error: string | null }
export interface Cover { id: string; status: string; prompt: string; style: string; model: string | null; quality: string | null; error: string | null; image_url: string | null; applied_playlist_id: string | null; created_at: string; finished_at: string | null }
export interface Quota { unlimited: boolean; limit: number; used: number; remaining: number | null; period_key: string; resets_at: string | null; premium: boolean; available: boolean }
