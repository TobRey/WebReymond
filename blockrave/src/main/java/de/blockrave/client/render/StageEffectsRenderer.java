package de.blockrave.client.render;

import com.mojang.blaze3d.vertex.PoseStack;
import com.mojang.blaze3d.vertex.VertexConsumer;
import de.blockrave.BlockRaveConfig;
import de.blockrave.client.ClientRaveState;
import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.show.ShowInput;
import de.blockrave.core.show.ShowPalette;
import de.blockrave.core.show.ShowProgram;
import de.blockrave.core.stage.PartType;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.core.stage.StagePart;
import de.blockrave.core.stage.StageTransform;
import de.blockrave.registry.ModBlocks;
import it.unimi.dsi.fastutil.ints.IntArrayList;
import net.minecraft.client.Minecraft;
import net.minecraft.client.multiplayer.ClientLevel;
import net.minecraft.client.renderer.MultiBufferSource;
import net.minecraft.core.BlockPos;
import net.minecraft.util.Mth;
import net.minecraft.world.item.component.FireworkExplosion;
import net.minecraft.world.level.ClipContext;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.phys.BlockHitResult;
import net.minecraft.world.phys.HitResult;
import net.minecraft.world.phys.Vec3;
import net.minecraft.world.phys.shapes.CollisionContext;
import org.joml.Matrix4f;
import org.joml.Vector3d;

import java.util.ArrayList;
import java.util.List;
import java.util.Random;

/**
 * Zeichnet die automatische Lichtshow clientseitig: Lichtkegel der Moving Heads, Laserfaecher,
 * Stroboskope, LED-Waende, leuchtende Tanzflaeche, Frontpaneele, Nebel und Feuerwerk.
 *
 * <p>Alle Bewegungen ergeben sich aus der gemeinsamen Abspielzeit (Serveruhr) und den mit dem Audio
 * uebertragenen Analysewerten &ndash; sie sind auf allen Clients gleich. Lichtkegel und Laser enden an
 * festen Bloecken (Strahlverfolgung) und werden von davorliegenden Bloecken verdeckt (Tiefentest).
 * Es handelt sich um sichtbare Strahlen und leuchtende Flaechen, nicht um dynamische Weltbeleuchtung.
 */
public final class StageEffectsRenderer {
    private static final double MAX_BEAM = 48;
    private static final double MAX_LASER = 64;

    private final StageLayout.Fixtures fx = StageLayout.get().fixtures();
    private final ShowProgram program = new ShowProgram(fx);
    private final ShowProgram.Output out = new ShowProgram.Output(fx);
    private final ShowInput input = new ShowInput();
    private final double[] smoothYaw = new double[fx.movingHeads().size()];
    private final double[] smoothPitch = new double[fx.movingHeads().size()];
    private boolean smoothInit;
    private long lastFrameNanos;
    private long lastEventCheck;
    private final List<Puff> puffs = new ArrayList<>();
    private final Random random = new Random();
    private boolean[] partPresent = new boolean[0];
    private int presenceTick = -1;

    private static final class Puff {
        double x, y, z, vx, vy, vz, size;
        float age, life;
        int color;
    }

    public void render(PoseStack pose, Vec3 cam, float partialTick) {
        ClientRaveState state = ClientRaveState.get();
        Minecraft mc = Minecraft.getInstance();
        ClientLevel level = mc.level;
        if (state == null || level == null || state.transform() == null || !state.stageInCurrentDimension()) {
            puffs.clear();
            return;
        }
        StageTransform t = state.transform();
        double[] c = t.toWorld(fx.center()[0], fx.center()[1], fx.center()[2]);
        if (cam.distanceToSqr(c[0], c[1], c[2]) > 320 * 320) return;

        long nanos = System.nanoTime();
        float dt = lastFrameNanos == 0 ? 0.016f : Math.min(0.1f, (nanos - lastFrameNanos) / 1e9f);
        lastFrameNanos = nanos;

        long now = state.showTimeMicros();
        state.timeline.fill(now, input);
        program.evaluate(input, out);
        BlockRaveConfig.Quality q = BlockRaveConfig.EFFECT_QUALITY.get();
        BlockRaveConfig.StrobeMode strobeMode = BlockRaveConfig.STROBE_MODE.get();
        updatePresence(level, t, (int) level.getGameTime());

        Matrix4f m = pose.last().pose();
        MultiBufferSource.BufferSource buffers = mc.renderBuffers().bufferSource();

        // 1) Leuchtende Flaechen
        VertexConsumer em = buffers.getBuffer(RaveRenderTypes.EMISSIVE);
        renderSurfaces(em, m, cam, t, q);
        buffers.endBatch(RaveRenderTypes.EMISSIVE);

        // 2) Strahlen (additiv)
        VertexConsumer beam = buffers.getBuffer(RaveRenderTypes.LIGHT_BEAM);
        renderHeads(beam, m, cam, t, level, q, dt);
        renderLasers(beam, m, cam, t, level, q);
        renderStrobes(beam, m, cam, t, strobeMode);
        buffers.endBatch(RaveRenderTypes.LIGHT_BEAM);

        // 3) Nebel
        if (BlockRaveConfig.FOG_ENABLED.get()) {
            spawnFog(t, dt);
            updateAndRenderFog(buffers, m, cam, dt);
        } else {
            puffs.clear();
        }

        // 4) Feuerwerk bei musikalischen Hoehepunkten (einmal pro Ereignis)
        handleEvents(level, t, now, strobeMode);
    }

    // ------------------------------------------------------------------ Anwesenheit der Bauteile

    /** Effekte nur an Positionen zeichnen, an denen das Bauteil tatsaechlich steht (1x pro Sekunde geprueft). */
    private void updatePresence(ClientLevel level, StageTransform t, int tick) {
        List<StagePart> parts = StageLayout.get().parts();
        if (partPresent.length != parts.size()) {
            partPresent = new boolean[parts.size()];
            presenceTick = -1000;
        }
        if (Math.abs(tick - presenceTick) < 20) return;
        presenceTick = tick;
        BlockPos.MutableBlockPos p = new BlockPos.MutableBlockPos();
        for (int i = 0; i < parts.size(); i++) {
            StagePart sp = parts.get(i);
            p.set(t.worldX(sp.x(), sp.z()), t.worldY(sp.y()), t.worldZ(sp.x(), sp.z()));
            Block expected = ModBlocks.blockFor(sp.type());
            partPresent[i] = level.isLoaded(p) && level.getBlockState(p).is(expected);
        }
    }

    private final java.util.IdentityHashMap<StagePart, Integer> partIndex = new java.util.IdentityHashMap<>();

    {
        List<StagePart> all = StageLayout.get().parts();
        for (int i = 0; i < all.size(); i++) partIndex.put(all.get(i), i);
    }

    private boolean present(StagePart part) {
        Integer i = partIndex.get(part);
        return i != null && i < partPresent.length && partPresent[i];
    }

    // ------------------------------------------------------------------ Flaechen

    private void renderSurfaces(VertexConsumer vc, Matrix4f m, Vec3 cam, StageTransform t, BlockRaveConfig.Quality q) {
        int sub = q == BlockRaveConfig.Quality.HOCH ? 3 : q == BlockRaveConfig.Quality.MITTEL ? 2 : 1;
        // LED-Panels
        for (StagePart p : fx.ledPanels()) {
            if (!present(p)) continue;
            int wall;
            double u0, v0, du, dv;
            if (p.z() == StageLayout.DECK_Z1 - 1) { // grosse Wand x=8..26, y=2..13
                wall = 0;
                u0 = (p.x() - 8) / 19.0;
                v0 = (p.y() - 2) / 12.0;
                du = 1 / 19.0;
                dv = 1 / 12.0;
            } else if (p.y() == StageLayout.TRUSS_Y + 1) { // Banner
                wall = 2;
                u0 = (p.x() - 6) / 23.0;
                v0 = 0;
                du = 1 / 23.0;
                dv = 1;
            } else { // Seitentuerme (4x4)
                wall = 1;
                int dx = p.x() < StageLayout.ANCHOR_X ? p.x() : p.x() - (StageLayout.WIDTH - 4);
                u0 = dx / 4.0;
                v0 = (p.y() - 13) / 4.0;
                du = 0.25;
                dv = 0.25;
            }
            for (int a = 0; a < sub; a++) {
                for (int b = 0; b < sub; b++) {
                    double u = u0 + du * (a + 0.5) / sub;
                    double v = v0 + dv * (b + 0.5) / sub;
                    int col = program.ledColor(wall, u, v);
                    double x0 = p.x() + (double) a / sub, x1 = p.x() + (double) (a + 1) / sub;
                    double y0 = p.y() + (double) b / sub, y1 = p.y() + (double) (b + 1) / sub;
                    double gap = sub > 1 ? 0.02 : 0.04; // sichtbares LED-Raster
                    quadLocal(vc, m, cam, t, x0 + gap, y0 + gap, x1 - gap, y1 - gap, p.z() - 0.004, col);
                }
            }
        }
        // Tanzflaeche (Oberseite)
        for (StagePart p : fx.floorTiles()) {
            if (!present(p)) continue;
            int col = program.floorColor(p.x(), p.z());
            topLocal(vc, m, cam, t, p.x() + 0.06, p.z() + 0.06, p.x() + 0.94, p.z() + 0.94, 1.004, col);
        }
        // Leuchtende Bühnenfront
        int front = ShowPalette.scale(out.frontColor, out.frontBrightness);
        for (StagePart p : fx.frontPanels()) {
            if (!present(p)) continue;
            quadLocal(vc, m, cam, t, p.x() + 0.05, 0.1, p.x() + 0.95, 1.9, p.z() - 0.004, front);
        }
    }

    /** Senkrechtes Rechteck parallel zur Buehnenfront bei lokalem z. */
    private static void quadLocal(VertexConsumer vc, Matrix4f m, Vec3 cam, StageTransform t, double x0, double y0, double x1,
                                  double y1, double z, int rgb) {
        double[] a = t.toWorld(x0, y0, z), b = t.toWorld(x1, y0, z), cc = t.toWorld(x1, y1, z), d = t.toWorld(x0, y1, z);
        int r = (rgb >> 16) & 0xFF, g = (rgb >> 8) & 0xFF, bl = rgb & 0xFF;
        // Reihenfolge so, dass die Vorderseite zum Publikum (lokal -z) zeigt
        vtx(vc, m, cam, a, r, g, bl, 255);
        vtx(vc, m, cam, d, r, g, bl, 255);
        vtx(vc, m, cam, cc, r, g, bl, 255);
        vtx(vc, m, cam, b, r, g, bl, 255);
    }

    private static void topLocal(VertexConsumer vc, Matrix4f m, Vec3 cam, StageTransform t, double x0, double z0, double x1,
                                 double z1, double y, int rgb) {
        double[] a = t.toWorld(x0, y, z0), b = t.toWorld(x1, y, z0), cc = t.toWorld(x1, y, z1), d = t.toWorld(x0, y, z1);
        int r = (rgb >> 16) & 0xFF, g = (rgb >> 8) & 0xFF, bl = rgb & 0xFF;
        // Render-Typ ohne Backface-Culling: Umlaufsinn (abhaengig von der Drehung) spielt keine Rolle
        vtx(vc, m, cam, a, r, g, bl, 255);
        vtx(vc, m, cam, b, r, g, bl, 255);
        vtx(vc, m, cam, cc, r, g, bl, 255);
        vtx(vc, m, cam, d, r, g, bl, 255);
    }

    private static void vtx(VertexConsumer vc, Matrix4f m, Vec3 cam, double[] p, int r, int g, int b, int a) {
        vc.addVertex(m, (float) (p[0] - cam.x), (float) (p[1] - cam.y), (float) (p[2] - cam.z)).setColor(r, g, b, a);
    }

    private static void vtx(VertexConsumer vc, Matrix4f m, Vec3 cam, Vector3d p, int r, int g, int b, int a) {
        vc.addVertex(m, (float) (p.x - cam.x), (float) (p.y - cam.y), (float) (p.z - cam.z)).setColor(r, g, b, a);
    }

    // ------------------------------------------------------------------ Moving Heads

    private void renderHeads(VertexConsumer vc, Matrix4f m, Vec3 cam, StageTransform t, ClientLevel level,
                             BlockRaveConfig.Quality q, float dt) {
        int sides = q == BlockRaveConfig.Quality.HOCH ? 14 : q == BlockRaveConfig.Quality.MITTEL ? 10 : 6;
        float k = 1f - (float) Math.exp(-dt / 0.12f); // sanfte Nachfuehrung, auf allen Clients gleich schnell
        for (int i = 0; i < out.heads.length; i++) {
            ShowProgram.Head h = out.heads[i];
            if (!smoothInit) {
                smoothYaw[i] = h.yaw;
                smoothPitch[i] = h.pitch;
            } else {
                smoothYaw[i] += (h.yaw - smoothYaw[i]) * k;
                smoothPitch[i] += (h.pitch - smoothPitch[i]) * k;
            }
            StagePart p = fx.movingHeads().get(i);
            if (h.intensity <= 0.01f || !present(p)) continue;
            boolean hanging = p.type() == PartType.MOVING_HEAD_HANGING;
            double[] o = t.toWorld(p.x() + 0.5, p.y() + (hanging ? 0.35 : 0.65), p.z() + 0.5);
            double cy = Math.cos(smoothPitch[i]);
            double[] d = t.dirToWorld(Math.sin(smoothYaw[i]) * cy, Math.sin(smoothPitch[i]), -Math.cos(smoothYaw[i]) * cy);
            Vector3d origin = new Vector3d(o[0], o[1], o[2]);
            Vector3d dir = new Vector3d(d[0], d[1], d[2]).normalize();
            double len = clip(level, origin, dir, MAX_BEAM, 0.55);
            float a0 = Math.min(1f, h.intensity) * 0.55f;
            cone(vc, m, cam, origin, dir, len, 0.12, 0.12 + len * Math.tan(h.width), h.color, a0, sides);
            // innerer, hellerer Kern
            cone(vc, m, cam, origin, dir, len * 0.7, 0.05, 0.05 + len * 0.7 * Math.tan(h.width * 0.35), ShowPalette.mix(h.color, ShowPalette.WHITE, 0.4f), a0 * 0.6f, Math.max(4, sides / 2));
            // Leuchtende Linse
            billboard(vc, m, cam, origin.x + dir.x * 0.3, origin.y + dir.y * 0.3, origin.z + dir.z * 0.3, 0.35, h.color, Math.min(1f, a0 * 1.6f));
        }
        smoothInit = true;
    }

    /** Laenge bis zum ersten festen Block (Strahlen gehen nicht durch Waende). */
    private static double clip(ClientLevel level, Vector3d origin, Vector3d dir, double max, double startOffset) {
        Vec3 from = new Vec3(origin.x + dir.x * startOffset, origin.y + dir.y * startOffset, origin.z + dir.z * startOffset);
        Vec3 to = new Vec3(origin.x + dir.x * max, origin.y + dir.y * max, origin.z + dir.z * max);
        BlockHitResult hit = level.clip(new ClipContext(from, to, ClipContext.Block.VISUAL, ClipContext.Fluid.NONE, CollisionContext.empty()));
        if (hit.getType() == HitResult.Type.MISS) return max;
        return Math.max(0.6, hit.getLocation().distanceTo(new Vec3(origin.x, origin.y, origin.z)));
    }

    private static void cone(VertexConsumer vc, Matrix4f m, Vec3 cam, Vector3d o, Vector3d d, double len, double r0,
                             double r1, int rgb, float alpha, int sides) {
        Vector3d u = new Vector3d(Math.abs(d.y) < 0.9 ? 0 : 1, Math.abs(d.y) < 0.9 ? 1 : 0, 0).cross(d).normalize();
        Vector3d v = new Vector3d(d).cross(u).normalize();
        int r = (rgb >> 16) & 0xFF, g = (rgb >> 8) & 0xFF, b = rgb & 0xFF;
        int a0 = (int) (alpha * 255);
        Vector3d end = new Vector3d(d).mul(len).add(o);
        for (int i = 0; i < sides; i++) {
            double t0 = Math.PI * 2 * i / sides, t1 = Math.PI * 2 * (i + 1) / sides;
            Vector3d n0 = new Vector3d(u).mul(Math.cos(t0)).add(new Vector3d(v).mul(Math.sin(t0)));
            Vector3d n1 = new Vector3d(u).mul(Math.cos(t1)).add(new Vector3d(v).mul(Math.sin(t1)));
            vtx(vc, m, cam, new Vector3d(n0).mul(r0).add(o), r, g, b, a0);
            vtx(vc, m, cam, new Vector3d(n1).mul(r0).add(o), r, g, b, a0);
            vtx(vc, m, cam, new Vector3d(n1).mul(r1).add(end), r, g, b, 0);
            vtx(vc, m, cam, new Vector3d(n0).mul(r1).add(end), r, g, b, 0);
        }
    }

    private static void billboard(VertexConsumer vc, Matrix4f m, Vec3 cam, double x, double y, double z, double size, int rgb,
                                  float alpha) {
        Vector3d toCam = new Vector3d(cam.x - x, cam.y - y, cam.z - z).normalize();
        Vector3d u = new Vector3d(0, 1, 0).cross(toCam);
        if (u.lengthSquared() < 1e-6) u.set(1, 0, 0);
        u.normalize().mul(size);
        Vector3d v = new Vector3d(toCam).cross(u).normalize().mul(size);
        Vector3d c = new Vector3d(x, y, z);
        int r = (rgb >> 16) & 0xFF, g = (rgb >> 8) & 0xFF, b = rgb & 0xFF, a = (int) (Mth.clamp(alpha, 0f, 1f) * 255);
        vtx(vc, m, cam, new Vector3d(c).sub(u).sub(v), r, g, b, a);
        vtx(vc, m, cam, new Vector3d(c).add(u).sub(v), r, g, b, a);
        vtx(vc, m, cam, new Vector3d(c).add(u).add(v), r, g, b, a);
        vtx(vc, m, cam, new Vector3d(c).sub(u).add(v), r, g, b, a);
    }

    // ------------------------------------------------------------------ Laser

    private void renderLasers(VertexConsumer vc, Matrix4f m, Vec3 cam, StageTransform t, ClientLevel level, BlockRaveConfig.Quality q) {
        for (int i = 0; i < out.lasers.length; i++) {
            ShowProgram.Laser l = out.lasers[i];
            StagePart p = fx.lasers().get(i);
            if (!l.on || l.intensity <= 0.01f || !present(p)) continue;
            int beams = q == BlockRaveConfig.Quality.NIEDRIG ? Math.min(3, l.beams) : l.beams;
            double[] o = t.toWorld(p.x() + 0.5, p.y() + 0.5, p.z() + 0.2);
            Vector3d origin = new Vector3d(o[0], o[1], o[2]);
            for (int b = 0; b < beams; b++) {
                double off = beams == 1 ? 0 : (b - (beams - 1) / 2.0) / ((beams - 1) / 2.0);
                double yaw = l.yaw + off * l.spread;
                double cy = Math.cos(l.pitch);
                double[] d = t.dirToWorld(Math.sin(yaw) * cy, Math.sin(l.pitch), -Math.cos(yaw) * cy);
                Vector3d dir = new Vector3d(d[0], d[1], d[2]).normalize();
                double len = clip(level, origin, dir, MAX_LASER, 0.4);
                laserLine(vc, m, cam, origin, dir, len, l.color, l.intensity);
            }
            billboard(vc, m, cam, origin.x, origin.y, origin.z, 0.25, l.color, l.intensity);
        }
    }

    private static void laserLine(VertexConsumer vc, Matrix4f m, Vec3 cam, Vector3d o, Vector3d d, double len, int rgb, float alpha) {
        Vector3d end = new Vector3d(d).mul(len).add(o);
        Vector3d mid = new Vector3d(o).add(end).mul(0.5);
        Vector3d toCam = new Vector3d(cam.x - mid.x, cam.y - mid.y, cam.z - mid.z);
        Vector3d side = new Vector3d(d).cross(toCam);
        if (side.lengthSquared() < 1e-9) side.set(0, 1, 0);
        side.normalize().mul(0.035);
        int r = (rgb >> 16) & 0xFF, g = (rgb >> 8) & 0xFF, b = rgb & 0xFF;
        int a = (int) (Math.min(1f, alpha) * 230);
        vtx(vc, m, cam, new Vector3d(o).sub(side), r, g, b, a);
        vtx(vc, m, cam, new Vector3d(o).add(side), r, g, b, a);
        vtx(vc, m, cam, new Vector3d(end).add(side), r, g, b, a / 3);
        vtx(vc, m, cam, new Vector3d(end).sub(side), r, g, b, a / 3);
        // Leuchtpunkt am Auftreffpunkt
        billboard(vc, m, cam, end.x, end.y, end.z, 0.12, rgb, alpha * 0.8f);
    }

    // ------------------------------------------------------------------ Stroboskope

    private void renderStrobes(VertexConsumer vc, Matrix4f m, Vec3 cam, StageTransform t, BlockRaveConfig.StrobeMode mode) {
        if (mode == BlockRaveConfig.StrobeMode.AUS) return;
        for (int i = 0; i < out.strobes.length; i++) {
            float s = out.strobes[i];
            if (mode == BlockRaveConfig.StrobeMode.REDUZIERT) {
                // hoechstens jeder zweite Beat, deutlich schwaecher
                s = (input.frame.beatIndex() % 2 == 0) ? s * 0.35f : 0f;
            }
            StagePart p = fx.strobes().get(i);
            if (s <= 0.01f || !present(p)) continue;
            double[] o = t.toWorld(p.x() + 0.5, p.y() + 0.5, p.z() - 0.1);
            billboard(vc, m, cam, o[0], o[1], o[2], 1.4, ShowPalette.WHITE, s);
            billboard(vc, m, cam, o[0], o[1], o[2], 3.5, ShowPalette.WHITE, s * 0.25f);
        }
    }

    // ------------------------------------------------------------------ Nebel

    private void spawnFog(StageTransform t, float dt) {
        float amount = BlockRaveConfig.PARTICLE_AMOUNT.get() / 100f;
        int max = (int) (140 * amount);
        for (int i = 0; i < out.fogRates.length; i++) {
            float rate = out.fogRates[i] * 18f * amount; // Schwaden pro Sekunde
            if (rate <= 0) continue;
            float expected = rate * dt;
            while (expected > 0 && puffs.size() < max) {
                if (random.nextFloat() >= expected) break;
                expected -= 1;
                StagePart p = fx.fogMachines().get(i);
                double[] o = t.toWorld(p.x() + 0.5, p.y() + 0.4, p.z() - 0.2);
                double[] d = t.dirToWorld((random.nextDouble() - 0.5) * 0.6, 0.05, -1);
                Puff f = new Puff();
                f.x = o[0];
                f.y = o[1];
                f.z = o[2];
                double speed = 2.5 + random.nextDouble() * 1.5;
                f.vx = d[0] * speed;
                f.vy = 0.15 + random.nextDouble() * 0.2;
                f.vz = d[2] * speed;
                f.size = 0.8;
                f.life = 4f + random.nextFloat() * 3f;
                f.color = ShowPalette.mix(ShowPalette.WHITE, ShowPalette.color(input.frame.beatIndex() / 4 + i), 0.25f);
                puffs.add(f);
            }
        }
    }

    private void updateAndRenderFog(MultiBufferSource.BufferSource buffers, Matrix4f m, Vec3 cam, float dt) {
        if (puffs.isEmpty()) return;
        VertexConsumer vc = buffers.getBuffer(RaveRenderTypes.FOG);
        for (int i = puffs.size() - 1; i >= 0; i--) {
            Puff f = puffs.get(i);
            f.age += dt;
            if (f.age >= f.life) {
                puffs.remove(i);
                continue;
            }
            double drag = Math.exp(-dt * 0.9);
            f.vx *= drag;
            f.vz *= drag;
            f.vy *= drag;
            f.x += f.vx * dt;
            f.y += f.vy * dt;
            f.z += f.vz * dt;
            f.size += dt * 0.9;
            float fade = Math.min(1f, f.age / 0.6f) * (1f - f.age / f.life);
            Vector3d toCam = new Vector3d(cam.x - f.x, cam.y - f.y, cam.z - f.z).normalize();
            Vector3d u = new Vector3d(0, 1, 0).cross(toCam);
            if (u.lengthSquared() < 1e-6) u.set(1, 0, 0);
            u.normalize().mul(f.size);
            Vector3d v = new Vector3d(toCam).cross(u).normalize().mul(f.size);
            int r = (f.color >> 16) & 0xFF, g = (f.color >> 8) & 0xFF, b = f.color & 0xFF, a = (int) (fade * 70);
            Vector3d c = new Vector3d(f.x, f.y, f.z);
            fogVtx(vc, m, cam, new Vector3d(c).sub(u).sub(v), 0, 1, r, g, b, a);
            fogVtx(vc, m, cam, new Vector3d(c).add(u).sub(v), 1, 1, r, g, b, a);
            fogVtx(vc, m, cam, new Vector3d(c).add(u).add(v), 1, 0, r, g, b, a);
            fogVtx(vc, m, cam, new Vector3d(c).sub(u).add(v), 0, 0, r, g, b, a);
        }
        buffers.endBatch(RaveRenderTypes.FOG);
    }

    private static void fogVtx(VertexConsumer vc, Matrix4f m, Vec3 cam, Vector3d p, float u, float v, int r, int g, int b, int a) {
        vc.addVertex(m, (float) (p.x - cam.x), (float) (p.y - cam.y), (float) (p.z - cam.z)).setUv(u, v).setColor(r, g, b, a);
    }

    // ------------------------------------------------------------------ Feuerwerk

    private void handleEvents(ClientLevel level, StageTransform t, long now, BlockRaveConfig.StrobeMode mode) {
        long from = lastEventCheck;
        lastEventCheck = now;
        if (from == 0 || now - from > 1_000_000) return; // nach Pausen/Beitritt keine alten Ereignisse nachholen
        float amount = BlockRaveConfig.PARTICLE_AMOUNT.get() / 100f;
        if (amount <= 0) return;
        ClientRaveState state = ClientRaveState.get();
        state.timeline.forEachBetween(from, now, (time, f) -> {
            if (!ShowProgram.fireworkTrigger(f)) return;
            boolean big = f.has(AnalysisFrame.PEAK);
            double[][] points = fx.fireworkPoints();
            int count = big ? points.length : 2;
            for (int i = 0; i < count; i++) {
                double[] pt = points[(i + f.beatIndex()) % points.length];
                double[] w = t.toWorld(pt[0], pt[1] + 4 + (i % 2) * 3, pt[2]);
                IntArrayList colors = new IntArrayList();
                colors.add(ShowPalette.color(f.beatIndex() + i));
                colors.add(ShowPalette.color(f.beatIndex() + i + 1));
                IntArrayList fade = new IntArrayList();
                fade.add(ShowPalette.WHITE);
                boolean flicker = mode == BlockRaveConfig.StrobeMode.VOLL;
                FireworkExplosion ex = new FireworkExplosion(big ? FireworkExplosion.Shape.LARGE_BALL : FireworkExplosion.Shape.BURST,
                        colors, fade, big, flicker);
                level.createFireworks(w[0], w[1], w[2], 0, 0, 0, List.of(ex));
            }
        });
    }
}
