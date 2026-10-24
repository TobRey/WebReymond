package de.blockrave.core.stage;

/** Richtung im lokalen Buehnen-Koordinatensystem. Publikum steht bei z &lt; 0 und blickt nach +z. */
public enum LocalDir {
    /** zum Publikum (-z) */
    FRONT,
    /** von Publikum weg (+z) */
    BACK,
    /** aus Sicht des Publikums links (-x) */
    LEFT,
    /** aus Sicht des Publikums rechts (+x) */
    RIGHT,
    UP,
    DOWN
}
