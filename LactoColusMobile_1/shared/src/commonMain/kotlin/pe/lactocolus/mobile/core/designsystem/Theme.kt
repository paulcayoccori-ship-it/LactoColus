package pe.lactocolus.mobile.core.designsystem

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf

/** User theme preference (Perfil screen): claro / oscuro / sistema. */
enum class TemaApp { CLARO, OSCURO, SISTEMA }

/** Extra semantic colors Material 3 has no slot for (success/warning + their containers). */
@Immutable
data class ColoresSemanticos(
    val exito: androidx.compose.ui.graphics.Color,
    val exitoContenedor: androidx.compose.ui.graphics.Color,
    val advertencia: androidx.compose.ui.graphics.Color,
    val advertenciaContenedor: androidx.compose.ui.graphics.Color,
)

val LocalColoresSemanticos = staticCompositionLocalOf {
    ColoresSemanticos(
        exito = LactoColors.Success,
        exitoContenedor = LactoColors.SuccessContainer,
        advertencia = LactoColors.Warning,
        advertenciaContenedor = LactoColors.WarningContainer,
    )
}

@Composable
fun LactoColusTheme(
    tema: TemaApp = TemaApp.SISTEMA,
    content: @Composable () -> Unit,
) {
    val oscuro = when (tema) {
        TemaApp.CLARO -> false
        TemaApp.OSCURO -> true
        TemaApp.SISTEMA -> isSystemInDarkTheme()
    }
    val colorScheme = if (oscuro) LactoDarkColorScheme else LactoLightColorScheme
    val semanticos = if (oscuro) {
        ColoresSemanticos(
            exito = LactoColors.DarkSuccess,
            exitoContenedor = LactoColors.DarkSuccessContainer,
            advertencia = LactoColors.DarkWarning,
            advertenciaContenedor = LactoColors.DarkWarningContainer,
        )
    } else {
        ColoresSemanticos(
            exito = LactoColors.Success,
            exitoContenedor = LactoColors.SuccessContainer,
            advertencia = LactoColors.Warning,
            advertenciaContenedor = LactoColors.WarningContainer,
        )
    }

    androidx.compose.runtime.CompositionLocalProvider(LocalColoresSemanticos provides semanticos) {
        MaterialTheme(
            colorScheme = colorScheme,
            typography = LactoTypography,
            shapes = LactoShapes,
            content = content,
        )
    }
}

/** Shorthand for the semantic colors inside composables: `LactoTheme.semanticos.exito`. */
object LactoTheme {
    val semanticos: ColoresSemanticos
        @Composable get() = LocalColoresSemanticos.current
}
