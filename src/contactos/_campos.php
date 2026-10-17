<?php
/**
 * Formulario de contacto que comparten crear.php y editar.php.
 * Usa $nombre, $tipo, $telefono y $correo de la página que lo incluye.
 * $textoBoton es opcional.
 */
?>
<form method="POST" class="adi-card adi-card-body">
    <div class="row g-3 mb-4">
        <div class="col-sm-8">
            <label for="nombre" class="form-label">Nombre completo o entidad *</label>
            <input type="text" class="form-control" id="nombre" name="nombre"
                   required minlength="2" value="<?= e($nombre) ?>">
        </div>
        <div class="col-sm-4">
            <label for="tipo" class="form-label">Tipo *</label>
            <select class="form-select" id="tipo" name="tipo" required>
                <?php if ($tipo === ''): ?>
                    <option value="">Elegí</option>
                <?php endif; ?>
                <?php foreach (TIPOS_CONTACTO as $valor => $etiqueta): ?>
                    <option value="<?= e($valor) ?>" <?= $tipo === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-5">
            <label for="telefono" class="form-label">Teléfono</label>
            <input type="tel" class="form-control" id="telefono" name="telefono"
                   value="<?= e($telefono) ?>">
        </div>
        <div class="col-sm-7">
            <label for="correo" class="form-label">Correo electrónico</label>
            <input type="email" class="form-control" id="correo" name="correo"
                   value="<?= e($correo) ?>">
        </div>
    </div>

    <p class="adi-sub mb-0">Los campos con * son obligatorios.</p>

    <div class="d-flex gap-2 pt-2 mt-3 border-top" style="border-color: var(--adi-border) !important">
        <button type="submit" class="btn adi-btn-primary mt-3"><?= e($textoBoton ?? 'Guardar contacto') ?></button>
        <a href="index.php" class="btn adi-btn-ghost mt-3">Cancelar</a>
    </div>
</form>
