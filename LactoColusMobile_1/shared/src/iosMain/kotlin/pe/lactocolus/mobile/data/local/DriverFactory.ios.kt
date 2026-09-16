package pe.lactocolus.mobile.data.local

import app.cash.sqldelight.driver.native.NativeSqliteDriver
import app.cash.sqldelight.db.SqlDriver
import pe.lactocolus.mobile.db.LactoColusDb

actual class DriverFactory {
    actual fun createDriver(): SqlDriver =
        NativeSqliteDriver(LactoColusDb.Schema, "lactocolus.db")
}
