package pe.lactocolus.mobile.core.designsystem

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.ui.graphics.Color

/**
 * LactoColus palette. Light values come straight from the approved design sheet;
 * the dark scheme keeps the same role relationships (violet identity, warm ground,
 * warning = ámbar, error = ladrillo) while lifting surfaces for contrast on a dark ground.
 */
object LactoColors {
    // Brand / status roles (shared by both themes)
    val Primary = Color(0xFF5A2D82)
    val PrimaryPressed = Color(0xFF3D1C5C)
    val PrimaryContainer = Color(0xFFEEE4F6)
    val OnPrimary = Color(0xFFFDF8EF)

    val Success = Color(0xFF1F6F5C)
    val SuccessContainer = Color(0xFFD7EFE6)
    val Warning = Color(0xFF96560C)
    val WarningContainer = Color(0xFFFFE7CC)
    val Error = Color(0xFFA02417)
    val ErrorContainer = Color(0xFFFBDED9)

    // Light surfaces
    val Background = Color(0xFFF5EAD8)
    val Surface = Color(0xFFFDF8EF)
    val OnBackground = Color(0xFF201E1D)
    val Outline = Color(0xFFDCD3C4)

    // Dark surfaces (derived)
    val DarkBackground = Color(0xFF1B1714)
    val DarkSurface = Color(0xFF262019)
    val DarkOnBackground = Color(0xFFF3ECDE)
    val DarkOutline = Color(0xFF4A4238)
    val DarkPrimary = Color(0xFFC9A8E6)
    val DarkPrimaryContainer = Color(0xFF3D1C5C)
    val DarkSuccess = Color(0xFF7FD3BE)
    val DarkSuccessContainer = Color(0xFF14453A)
    val DarkWarning = Color(0xFFF2B478)
    val DarkWarningContainer = Color(0xFF5A3410)
    val DarkError = Color(0xFFF0A79C)
    val DarkErrorContainer = Color(0xFF5C1810)
}

val LactoLightColorScheme: ColorScheme = lightColorScheme(
    primary = LactoColors.Primary,
    onPrimary = LactoColors.OnPrimary,
    primaryContainer = LactoColors.PrimaryContainer,
    onPrimaryContainer = LactoColors.PrimaryPressed,
    secondary = LactoColors.Primary,
    onSecondary = LactoColors.OnPrimary,
    secondaryContainer = LactoColors.PrimaryContainer,
    onSecondaryContainer = LactoColors.PrimaryPressed,
    tertiary = LactoColors.Success,
    onTertiary = Color.White,
    tertiaryContainer = LactoColors.SuccessContainer,
    onTertiaryContainer = Color(0xFF0C3329),
    background = LactoColors.Background,
    onBackground = LactoColors.OnBackground,
    surface = LactoColors.Surface,
    onSurface = LactoColors.OnBackground,
    surfaceVariant = LactoColors.Background,
    onSurfaceVariant = Color(0xFF52463A),
    outline = LactoColors.Outline,
    outlineVariant = LactoColors.Outline,
    error = LactoColors.Error,
    onError = Color.White,
    errorContainer = LactoColors.ErrorContainer,
    onErrorContainer = Color(0xFF3F0B05),
)

val LactoDarkColorScheme: ColorScheme = darkColorScheme(
    primary = LactoColors.DarkPrimary,
    onPrimary = Color(0xFF2A0F45),
    primaryContainer = LactoColors.DarkPrimaryContainer,
    onPrimaryContainer = Color(0xFFEEE4F6),
    secondary = LactoColors.DarkPrimary,
    onSecondary = Color(0xFF2A0F45),
    secondaryContainer = LactoColors.DarkPrimaryContainer,
    onSecondaryContainer = Color(0xFFEEE4F6),
    tertiary = LactoColors.DarkSuccess,
    onTertiary = Color(0xFF04241C),
    tertiaryContainer = LactoColors.DarkSuccessContainer,
    onTertiaryContainer = LactoColors.SuccessContainer,
    background = LactoColors.DarkBackground,
    onBackground = LactoColors.DarkOnBackground,
    surface = LactoColors.DarkSurface,
    onSurface = LactoColors.DarkOnBackground,
    surfaceVariant = Color(0xFF332B22),
    onSurfaceVariant = Color(0xFFD6C9B6),
    outline = LactoColors.DarkOutline,
    outlineVariant = LactoColors.DarkOutline,
    error = LactoColors.DarkError,
    onError = Color(0xFF3F0B05),
    errorContainer = LactoColors.DarkErrorContainer,
    onErrorContainer = LactoColors.ErrorContainer,
)
