package de.blockrave.client.render;

import com.mojang.blaze3d.vertex.PoseStack;
import com.mojang.blaze3d.vertex.VertexConsumer;
import de.blockrave.client.placement.PlacementController;
import de.blockrave.core.stage.PartType;
import de.blockrave.stage.StageFootprint;
import net.minecraft.client.Minecraft;
import net.minecraft.client.renderer.MultiBufferSource;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.world.phys.Vec3;
import org.joml.Matrix4f;

import java.util.HashMap;
import java.util.Map;
import java.util.Set;

/**
 * Transparente Vorschau der kompletten Buehne. Nur die Aussenflaechen werden gezeichnet; die Flaechen
 * werden zwischengespeichert und nur bei Aenderung von Position/Drehung neu berechnet.
 * Bloecke, die entfernt bzw. ersetzt wuerden, erscheinen rot &ndash; auch durch Gelaende hindurch.
 */
public final class PreviewRenderer {
    private long cachedRevision = -1;
    private float[] ghostQuads = new float[0];   // x,y,z,rgba-Index je Ecke
    private int[] ghostColors = new int[0];
    private float[] redQuads = new float[0];
    private int ghostCount, redCount;

    public void render(PoseStack pose, Vec3 cam) {
        PlacementController pc = PlacementController.INSTANCE;
        StageFootprint fp = pc.footprint();
        if (!pc.isActive() || fp == null) return;
        if (pc.revision() != cachedRevision) rebuild(pc, fp);
        Matrix4f m = pose.last().pose();
        MultiBufferSource.BufferSource buffers = Minecraft.getInstance().renderBuffers().bufferSource();

        VertexConsumer ghost = buffers.getBuffer(RaveRenderTypes.GHOST);
        for (int q = 0; q < ghostCount; q++) {
            int col = ghostColors[q];
            for (int k = 0; k < 4; k++) {
                int i = (q * 4 + k) * 3;
                ghost.addVertex(m, (float) (ghostQuads[i] - cam.x), (float) (ghostQuads[i + 1] - cam.y), (float) (ghostQuads[i + 2] - cam.z))
                        .setColor((col >> 16) & 0xFF, (col >> 8) & 0xFF, col & 0xFF, 90);
            }
        }
        buffers.endBatch(RaveRenderTypes.GHOST);

        VertexConsumer red = buffers.getBuffer(RaveRenderTypes.GHOST_XRAY);
        for (int q = 0; q < redCount; q++) {
            for (int k = 0; k < 4; k++) {
                int i = (q * 4 + k) * 3;
                red.addVertex(m, (float) (redQuads[i] - cam.x), (float) (redQuads[i + 1] - cam.y), (float) (redQuads[i + 2] - cam.z))
                        .setColor(255, 30, 30, 110);
            }
        }
        buffers.endBatch(RaveRenderTypes.GHOST_XRAY);

        // Umriss des exakten Eingriffsbereichs + Pfeil Richtung Publikum
        VertexConsumer lines = buffers.getBuffer(RaveRenderTypes.LINES_XRAY);
        box(lines, pose, cam, fp.min().getX(), fp.min().getY(), fp.min().getZ(), fp.max().getX() + 1, fp.max().getY() + 1,
                fp.max().getZ() + 1, 255, 255, 255);
        double[] a = fp.transform().toWorld(17.5, 0.1, -0.5), b = fp.transform().toWorld(17.5, 0.1, -6);
        double[] l = fp.transform().toWorld(15.5, 0.1, -4), r = fp.transform().toWorld(19.5, 0.1, -4);
        line(lines, pose, cam, a, b, 0, 255, 255);
        line(lines, pose, cam, b, l, 0, 255, 255);
        line(lines, pose, cam, b, r, 0, 255, 255);
        buffers.endBatch(RaveRenderTypes.LINES_XRAY);
    }

    private static int colorFor(PartType t) {
        return switch (t) {
            case DANCE_FLOOR -> 0xB040FF;
            case STAGE_DECK, STAGE_STEPS -> 0x707080;
            case STAGE_FRONT -> 0xFFFFFF;
            case SPEAKER -> 0x303030;
            case TRUSS_VERTICAL, TRUSS_ALONG_X, TRUSS_ALONG_Z -> 0xC0C8D0;
            case LED_PANEL -> 0xFF2EA6;
            case MOVING_HEAD, MOVING_HEAD_HANGING, LASER, STROBE, FOG_MACHINE -> 0xFFE01A;
            case DJ_BOOTH, DJ_BOOTH_SIDE -> 0x00E5FF;
        };
    }

    private void rebuild(PlacementController pc, StageFootprint fp) {
        cachedRevision = pc.revision();
        Map<Long, PartType> parts = new HashMap<>();
        for (StageFootprint.PlacedPart p : fp.parts()) parts.put(p.pos().asLong(), p.type());
        // Bauteile: nur Flaechen, an die kein anderes Bauteil grenzt
        java.util.ArrayList<Float> gq = new java.util.ArrayList<>();
        java.util.ArrayList<Integer> gc = new java.util.ArrayList<>();
        for (StageFootprint.PlacedPart p : fp.parts()) {
            for (Direction d : Direction.values()) {
                if (parts.containsKey(p.pos().relative(d).asLong())) continue;
                face(gq, p.pos(), d, 0.0);
                gc.add(colorFor(p.type()));
            }
        }
        ghostQuads = toArray(gq);
        ghostColors = gc.stream().mapToInt(Integer::intValue).toArray();
        ghostCount = ghostColors.length;
        // Betroffene Bloecke: Aussenflaechen der Konfliktmenge
        Set<BlockPos> conflicts = pc.conflicts();
        java.util.ArrayList<Float> rq = new java.util.ArrayList<>();
        for (BlockPos p : conflicts) {
            for (Direction d : Direction.values()) {
                if (conflicts.contains(p.relative(d))) continue;
                face(rq, p, d, 0.01);
            }
        }
        redQuads = toArray(rq);
        redCount = redQuads.length / 12;
    }

    private static float[] toArray(java.util.List<Float> l) {
        float[] a = new float[l.size()];
        for (int i = 0; i < a.length; i++) a[i] = l.get(i);
        return a;
    }

    private static void face(java.util.List<Float> out, BlockPos p, Direction d, double grow) {
        double x0 = p.getX() - grow, y0 = p.getY() - grow, z0 = p.getZ() - grow;
        double x1 = p.getX() + 1 + grow, y1 = p.getY() + 1 + grow, z1 = p.getZ() + 1 + grow;
        double[][] c = switch (d) {
            case DOWN -> new double[][]{{x0, y0, z0}, {x1, y0, z0}, {x1, y0, z1}, {x0, y0, z1}};
            case UP -> new double[][]{{x0, y1, z0}, {x0, y1, z1}, {x1, y1, z1}, {x1, y1, z0}};
            case NORTH -> new double[][]{{x0, y0, z0}, {x0, y1, z0}, {x1, y1, z0}, {x1, y0, z0}};
            case SOUTH -> new double[][]{{x0, y0, z1}, {x1, y0, z1}, {x1, y1, z1}, {x0, y1, z1}};
            case WEST -> new double[][]{{x0, y0, z0}, {x0, y0, z1}, {x0, y1, z1}, {x0, y1, z0}};
            case EAST -> new double[][]{{x1, y0, z0}, {x1, y1, z0}, {x1, y1, z1}, {x1, y0, z1}};
        };
        for (double[] v : c) {
            out.add((float) v[0]);
            out.add((float) v[1]);
            out.add((float) v[2]);
        }
    }

    private static void line(VertexConsumer vc, PoseStack pose, Vec3 cam, double[] a, double[] b, int r, int g, int bl) {
        float dx = (float) (b[0] - a[0]), dy = (float) (b[1] - a[1]), dz = (float) (b[2] - a[2]);
        float len = (float) Math.sqrt(dx * dx + dy * dy + dz * dz);
        if (len < 1e-6) return;
        dx /= len;
        dy /= len;
        dz /= len;
        PoseStack.Pose p = pose.last();
        vc.addVertex(p, (float) (a[0] - cam.x), (float) (a[1] - cam.y), (float) (a[2] - cam.z)).setColor(r, g, bl, 255).setNormal(p, dx, dy, dz);
        vc.addVertex(p, (float) (b[0] - cam.x), (float) (b[1] - cam.y), (float) (b[2] - cam.z)).setColor(r, g, bl, 255).setNormal(p, dx, dy, dz);
    }

    private static void box(VertexConsumer vc, PoseStack pose, Vec3 cam, double x0, double y0, double z0, double x1, double y1,
                            double z1, int r, int g, int b) {
        double[][] c = {{x0, y0, z0}, {x1, y0, z0}, {x1, y0, z1}, {x0, y0, z1}, {x0, y1, z0}, {x1, y1, z0}, {x1, y1, z1}, {x0, y1, z1}};
        int[][] e = {{0, 1}, {1, 2}, {2, 3}, {3, 0}, {4, 5}, {5, 6}, {6, 7}, {7, 4}, {0, 4}, {1, 5}, {2, 6}, {3, 7}};
        for (int[] ed : e) line(vc, pose, cam, c[ed[0]], c[ed[1]], r, g, b);
    }
}
