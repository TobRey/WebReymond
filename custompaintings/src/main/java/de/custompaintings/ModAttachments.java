package de.custompaintings;

import com.mojang.serialization.Codec;
import net.neoforged.neoforge.attachment.AttachmentType;
import net.neoforged.neoforge.registries.DeferredRegister;
import net.neoforged.neoforge.registries.NeoForgeRegistries;

import java.util.function.Supplier;

/**
 * Am Gemaelde gespeicherte Bild-ID (SHA-256 des PNG). Wird mit dem Gemaelde in der Welt gespeichert; das Bild
 * selbst liegt einmalig im Bildspeicher der Welt. Leer = normales Minecraft-Motiv.
 */
public final class ModAttachments {
    public static final DeferredRegister<AttachmentType<?>> TYPES =
            DeferredRegister.create(NeoForgeRegistries.Keys.ATTACHMENT_TYPES, CustomPaintings.MOD_ID);

    public static final Supplier<AttachmentType<String>> IMAGE = TYPES.register("image",
            () -> AttachmentType.builder(() -> "").serialize(Codec.STRING).build());

    private ModAttachments() {}
}
