package de.blockrave.network;

/** Bedienbefehle des DJ-Pults. */
public final class DjAction {
    public static final int PLAY = 0;
    public static final int PAUSE = 1;
    public static final int STOP = 2;
    public static final int NEXT = 3;
    public static final int SET_MODE = 4;          // arg: 0 MP3, 1 Live
    public static final int SET_VOLUME = 5;        // arg: 0..100
    public static final int PLAYLIST_ADD = 6;      // trackId
    public static final int PLAYLIST_REMOVE = 7;   // trackId
    public static final int PLAYLIST_MOVE = 8;     // trackId, arg: -1/+1
    public static final int QUEUE_ADD = 9;         // trackId
    public static final int QUEUE_REMOVE = 10;     // arg: Index
    public static final int QUEUE_MOVE = 11;       // trackId = Index als Text, arg: -1/+1
    public static final int DELETE_TRACK = 12;     // trackId
    public static final int PLAY_TRACK = 13;       // trackId (sofort spielen)
    public static final int LIVE_STOP = 14;        // laufende Live-Uebertragung beenden
    public static final int REQUEST_LIBRARY = 15;

    private DjAction() {}
}
