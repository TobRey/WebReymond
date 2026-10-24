package de.blockrave.client.render;

import com.mojang.blaze3d.vertex.DefaultVertexFormat;
import com.mojang.blaze3d.vertex.VertexFormat;
import de.blockrave.BlockRave;
import net.minecraft.client.renderer.GameRenderer;
import net.minecraft.client.renderer.RenderType;

import java.util.OptionalDouble;

/** Eigene Render-Typen fuer Lichteffekte (funktionieren ohne Shaderpacks). */
public final class RaveRenderTypes extends RenderType {
    private RaveRenderTypes(String name, VertexFormat format, VertexFormat.Mode mode, int size, boolean crumbling,
                            boolean sort, Runnable setup, Runnable clear) {
        super(name, format, mode, size, crumbling, sort, setup, clear);
    }

    /** Additive, tiefengetestete Lichtkegel/Laser: hinter Waenden unsichtbar, schreiben keine Tiefe. */
    public static final RenderType LIGHT_BEAM = create("blockrave_light_beam", DefaultVertexFormat.POSITION_COLOR,
            VertexFormat.Mode.QUADS, 1 << 18, false, false, CompositeState.builder()
                    .setShaderState(POSITION_COLOR_SHADER)
                    .setTransparencyState(LIGHTNING_TRANSPARENCY)
                    .setWriteMaskState(COLOR_WRITE)
                    .setCullState(NO_CULL)
                    .setDepthTestState(LEQUAL_DEPTH_TEST)
                    .createCompositeState(false));

    /** Selbstleuchtende Flaechen (LED-Panels, Tanzflaeche): voll hell, unabhaengig vom Weltlicht. */
    public static final RenderType EMISSIVE = create("blockrave_emissive", DefaultVertexFormat.POSITION_COLOR,
            VertexFormat.Mode.QUADS, 1 << 18, false, false, CompositeState.builder()
                    .setShaderState(POSITION_COLOR_SHADER)
                    .setTransparencyState(NO_TRANSPARENCY)
                    .setWriteMaskState(COLOR_DEPTH_WRITE)
                    .setCullState(CULL)
                    .setDepthTestState(LEQUAL_DEPTH_TEST)
                    .setLayeringState(POLYGON_OFFSET_LAYERING)
                    .createCompositeState(false));

    /** Halbtransparente Vorschau-Flaechen. */
    public static final RenderType GHOST = create("blockrave_ghost", DefaultVertexFormat.POSITION_COLOR,
            VertexFormat.Mode.QUADS, 1 << 18, false, true, CompositeState.builder()
                    .setShaderState(POSITION_COLOR_SHADER)
                    .setTransparencyState(TRANSLUCENT_TRANSPARENCY)
                    .setWriteMaskState(COLOR_WRITE)
                    .setCullState(NO_CULL)
                    .setDepthTestState(LEQUAL_DEPTH_TEST)
                    .createCompositeState(false));

    /** Rote Markierung betroffener Bloecke, auch durch Gelaende sichtbar. */
    public static final RenderType GHOST_XRAY = create("blockrave_ghost_xray", DefaultVertexFormat.POSITION_COLOR,
            VertexFormat.Mode.QUADS, 1 << 18, false, true, CompositeState.builder()
                    .setShaderState(POSITION_COLOR_SHADER)
                    .setTransparencyState(TRANSLUCENT_TRANSPARENCY)
                    .setWriteMaskState(COLOR_WRITE)
                    .setCullState(NO_CULL)
                    .setDepthTestState(NO_DEPTH_TEST)
                    .createCompositeState(false));

    /** Linien ohne Tiefentest (Umriss des Eingriffsbereichs). */
    public static final RenderType LINES_XRAY = create("blockrave_lines_xray", DefaultVertexFormat.POSITION_COLOR_NORMAL,
            VertexFormat.Mode.LINES, 1 << 16, false, false, CompositeState.builder()
                    .setShaderState(RENDERTYPE_LINES_SHADER)
                    .setLineState(new LineStateShard(OptionalDouble.of(2.5)))
                    .setLayeringState(VIEW_OFFSET_Z_LAYERING)
                    .setTransparencyState(TRANSLUCENT_TRANSPARENCY)
                    .setWriteMaskState(COLOR_WRITE)
                    .setCullState(NO_CULL)
                    .setDepthTestState(NO_DEPTH_TEST)
                    .createCompositeState(false));

    /** Weiche Nebelschwaden (Textur mit Alpha). */
    public static final RenderType FOG = create("blockrave_fog", DefaultVertexFormat.POSITION_TEX_COLOR,
            VertexFormat.Mode.QUADS, 1 << 16, false, true, CompositeState.builder()
                    .setShaderState(new ShaderStateShard(GameRenderer::getPositionTexColorShader))
                    .setTextureState(new TextureStateShard(BlockRave.id("textures/effect/fog.png"), false, false))
                    .setTransparencyState(TRANSLUCENT_TRANSPARENCY)
                    .setWriteMaskState(COLOR_WRITE)
                    .setCullState(NO_CULL)
                    .setDepthTestState(LEQUAL_DEPTH_TEST)
                    .createCompositeState(false));
}
