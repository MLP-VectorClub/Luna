#!/usr/bin/env bash
echo "##### post-receive hook #####"
read oldrev newrev refname
echo "Push triggered update to revision $newrev ($refname)"

RUN_FOR_REF="refs/heads/master"
if [[ "$refname" ==  "$RUN_FOR_REF" ]]; then
    GIT="env -i git"
    CMD_CD="cd $(readlink -nf "$PWD/..")"
    CMD_FETCH="$GIT fetch"
    CMD_COMPOSER="if [ -d vendor/ ]; then sudo chmod -R ug+rw vendor/; fi; sudo -u www-data composer install --optimize-autoloader --no-dev 2>&1"
    CMD_MIGRATE="sudo -u www-data php artisan migrate --force"
    CMD_NPM="sudo -u www-data npm install --production --no-save"
    # CMD_REDIS_CLEAR="# TODO"
    CMD_API_DOCS="sudo -u www-data php artisan api:schema"
    CMD_CACHE_CONFIG="sudo -u www-data php artisan config:cache"
    CMD_CACHE_ROUTES="sudo -u www-data php artisan route:cache"

    echo "$ $CMD_CD"
    eval ${CMD_CD}
    echo "$ $CMD_FETCH"
    eval ${CMD_FETCH}
    echo "$ $CMD_COMPOSER"
    eval ${CMD_COMPOSER}
    echo "$ $CMD_MIGRATE"
    eval ${CMD_MIGRATE}

    if $GIT diff --name-only $oldrev $newrev | grep "^package-lock.json"; then
        echo "$ $CMD_NPM"
        eval $CMD_NPM
    else
        echo "# Skipping npm install, lockfile not modified"
    fi

    if $GIT diff --name-only $oldrev $newrev | grep "^assets/"; then
        echo "$ $CMD_BUILD"
        eval $CMD_BUILD
    else
        echo "# Skipping asset rebuild, no changes in assets folder"
    fi

    if $GIT diff --name-only $oldrev $newrev | grep "^app"; then
        echo "$ $CMD_API_DOCS"
        eval $CMD_API_DOCS
    else
        echo "# Skipping API schema generation, no changes in app folder"
    fi

    echo "$ $CMD_CACHE_CONFIG"
    eval ${CMD_CACHE_CONFIG}

    echo "$ $CMD_CACHE_ROUTES"
    eval ${CMD_CACHE_ROUTES}

    # echo "$ $CMD_REDIS_CLEAR"
    # eval ${CMD_REDIS_CLEAR}
else
    echo "Ref does not match $RUN_FOR_REF, exiting."
fi

echo "##### end post-receive hook #####"
