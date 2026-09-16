package pe.lactocolus.mobile

import android.app.Application
import org.koin.android.ext.koin.androidContext
import org.koin.android.ext.koin.androidLogger
import pe.lactocolus.mobile.di.initKoin

class LactoColusApp : Application() {
    override fun onCreate() {
        super.onCreate()
        initKoin {
            androidLogger()
            androidContext(this@LactoColusApp)
        }
    }
}
