package pe.lactocolus.mobile.core.designsystem

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/**
 * Type roles: Caprasimo for titles and big numbers, Figtree for body, IBM Plex Mono for
 * codes. The branded TTFs are not bundled in this delivery (asset fetching is blocked in
 * this environment) — [DisplayFamily]/[BodyFamily]/[MonoFamily] fall back to system
 * families and are the single place to wire the real fonts later
 * (drop TTFs in `composeResources/font/` and build `FontFamily(Font(Res.font.…))`).
 */
val DisplayFamily: FontFamily = FontFamily.SansSerif   // ← Caprasimo
val BodyFamily: FontFamily = FontFamily.SansSerif      // ← Figtree
val MonoFamily: FontFamily = FontFamily.Monospace      // ← IBM Plex Mono

/** Big-number / code helpers used directly by indicator cards and código chips. */
val NumeroGrande = TextStyle(fontFamily = DisplayFamily, fontWeight = FontWeight.Normal, fontSize = 30.sp, lineHeight = 34.sp)
val CodigoMono = TextStyle(fontFamily = MonoFamily, fontWeight = FontWeight.Medium, fontSize = 12.sp, lineHeight = 16.sp)

val LactoTypography: Typography = Typography(
    displayLarge = TextStyle(fontFamily = DisplayFamily, fontWeight = FontWeight.Normal, fontSize = 40.sp, lineHeight = 44.sp),
    displaySmall = TextStyle(fontFamily = DisplayFamily, fontWeight = FontWeight.Normal, fontSize = 30.sp, lineHeight = 34.sp),
    headlineMedium = TextStyle(fontFamily = DisplayFamily, fontWeight = FontWeight.Normal, fontSize = 26.sp, lineHeight = 32.sp),
    headlineSmall = TextStyle(fontFamily = DisplayFamily, fontWeight = FontWeight.Normal, fontSize = 22.sp, lineHeight = 28.sp),
    titleLarge = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.SemiBold, fontSize = 19.sp, lineHeight = 24.sp),
    titleMedium = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.SemiBold, fontSize = 16.sp, lineHeight = 22.sp),
    bodyLarge = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.Normal, fontSize = 15.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.Normal, fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, lineHeight = 18.sp),
    labelMedium = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.Medium, fontSize = 13.sp, lineHeight = 16.sp),
    labelSmall = TextStyle(fontFamily = BodyFamily, fontWeight = FontWeight.Medium, fontSize = 13.sp, lineHeight = 16.sp),
)
