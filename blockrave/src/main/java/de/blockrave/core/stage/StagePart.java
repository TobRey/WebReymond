package de.blockrave.core.stage;

/** Ein Bauteil an einer lokalen Position. */
public record StagePart(int x, int y, int z, PartType type, LocalDir facing) {}
