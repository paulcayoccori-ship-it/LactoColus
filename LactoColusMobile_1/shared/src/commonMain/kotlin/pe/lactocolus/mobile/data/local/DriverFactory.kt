package pe.lactocolus.mobile.data.local

import app.cash.sqldelight.db.SqlDriver
import pe.lactocolus.mobile.db.LactoColusDb

/**
 * Platform driver factory. The Android implementation needs a [android.content.Context];
 * it is supplied through DI so common code never sees the platform type.
 */
expect class DriverFactory {
    fun createDriver(): SqlDriver
}

/** Builds the generated database on top of a platform driver. Schema migrations are
 *  versioned from v1: [LactoColusDb.Schema] carries the current version and SQLDelight
 *  runs any `.sqm` migration files placed next to the schema. */
fun createDatabase(driverFactory: DriverFactory): LactoColusDb =
    LactoColusDb(driverFactory.createDriver())
