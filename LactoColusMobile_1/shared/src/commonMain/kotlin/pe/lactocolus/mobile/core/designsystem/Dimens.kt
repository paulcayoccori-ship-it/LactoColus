package pe.lactocolus.mobile.core.designsystem

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp

/** Spacing scale: 4 · 8 · 16 · 24 · 40 dp. Nothing off-scale. */
object Space {
    val xs = 4.dp
    val sm = 8.dp
    val md = 16.dp
    val lg = 24.dp
    val xl = 40.dp
}

/** Corner radii: 8 · 16 · 22 · pill. No square corners anywhere. */
object Corner {
    val sm = 8.dp
    val md = 16.dp
    val lg = 22.dp
    val pill = 999.dp
}

val LactoShapes = Shapes(
    extraSmall = RoundedCornerShape(Corner.sm),
    small = RoundedCornerShape(Corner.sm),
    medium = RoundedCornerShape(Corner.md),
    large = RoundedCornerShape(Corner.lg),
    extraLarge = RoundedCornerShape(Corner.lg),
)

/** Minimum touch target; the primary action of each screen is taller. */
object Touch {
    val min = 48.dp
    val primaryAction = 64.dp
}

/** Three soft elevation steps. Cards use the lowest. */
object Elevation {
    val level0 = 0.dp
    val level1 = 1.dp
    val level2 = 3.dp
    val level3 = 6.dp
}
