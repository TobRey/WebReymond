package de.custompaintings.client;

import com.mojang.blaze3d.vertex.PoseStack;
import com.mojang.blaze3d.vertex.VertexConsumer;
import com.mojang.math.Axis;
import net.minecraft.client.renderer.MultiBufferSource;
import net.minecraft.client.renderer.RenderType;
import net.minecraft.client.renderer.entity.EntityRendererProvider;
import net.minecraft.client.renderer.entity.PaintingRenderer;
import net.minecraft.client.renderer.texture.OverlayTexture;
import net.minecraft.resources.ResourceLocation;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.entity.decoration.PaintingVariant;

/**
 * Ersetzt den Vanilla-Gemaelde-Renderer. Rahmen, Rueckseite und Motiv zeichnet weiterhin Minecraft;
 * hat das Gemaelde ein eigenes Bild, wird es direkt davor ueber die gesamte Vorderseite gelegt.
 */
public class CustomPaintingRenderer extends PaintingRenderer {
    /** Vanilla-Vorderseite liegt bei z = -1/32; das eigene Bild knapp davor. */
    private static final float FRONT_Z = -0.0335F;

    public CustomPaintingRenderer(EntityRendererProvider.Context context) {
        super(context);
    }

    @Override
    public void render(Painting entity, float entityYaw, float partialTicks, PoseStack poseStack, MultiBufferSource buffer,
                       int packedLight) {
        super.render(entity, entityYaw, partialTicks, poseStack, buffer, packedLight);
        ResourceLocation tex = ClientImages.INSTANCE.textureFor(entity.getId());
        if (tex == null) return;
        PaintingVariant variant = entity.getVariant().value();
        float w = variant.width(), h = variant.height();
        poseStack.pushPose();
        poseStack.mulPose(Axis.YP.rotationDegrees(180.0F - entityYaw));
        PoseStack.Pose pose = poseStack.last();
        VertexConsumer vc = buffer.getBuffer(RenderType.entitySolid(tex));
        float x0 = -w / 2, x1 = w / 2, y0 = -h / 2, y1 = h / 2;
        // Gleiche Eckreihenfolge und Textur-Ausrichtung wie die Vanilla-Vorderseite (vom Betrachter aus links = u 0)
        vertex(vc, pose, x1, y0, 0, 1, packedLight);
        vertex(vc, pose, x0, y0, 1, 1, packedLight);
        vertex(vc, pose, x0, y1, 1, 0, packedLight);
        vertex(vc, pose, x1, y1, 0, 0, packedLight);
        poseStack.popPose();
    }

    private static void vertex(VertexConsumer vc, PoseStack.Pose pose, float x, float y, float u, float v, int light) {
        vc.addVertex(pose, x, y, FRONT_Z)
                .setColor(-1)
                .setUv(u, v)
                .setOverlay(OverlayTexture.NO_OVERLAY)
                .setLight(light)
                .setNormal(pose, 0, 0, -1);
    }
}
