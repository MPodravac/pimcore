<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\ExtensionManager;

use ForceUTF8\Encoding;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\AdminBundle\HttpFoundation\JsonResponse;
use Pimcore\Cache\Symfony\CacheClearer;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Extension\Bundle\Exception\BundleNotFoundException;
use Pimcore\Extension\Bundle\PimcoreBundleInterface;
use Pimcore\Extension\Document\Areabrick\AreabrickInterface;
use Pimcore\Extension\Document\Areabrick\AreabrickManagerInterface;
use Pimcore\Logger;
use Pimcore\Routing\RouteReferenceInterface;
use Pimcore\Tool\AssetsInstaller;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @deprecated will be removed in Pimcore 11
 *
 * @internal
 */
class ExtensionManagerController extends AdminController implements KernelControllerEventInterface
{
    /**
     * @var AreabrickManagerInterface
     */
    private $areabrickManager;

    public function __construct(
        AreabrickManagerInterface $areabrickManager
    ) {
        $this->areabrickManager = $areabrickManager;
    }

    /**
     * {@inheritdoc}
     */
    public function onKernelControllerEvent(ControllerEvent $event)
    {
        $this->checkPermission('plugins');
    }

    /**
     * @Route("/admin/extensions", name="pimcore_admin_extensionmanager_extensionmanager_getextensions", methods={"GET"})
     *
     * @return JsonResponse
     */
    public function getExtensionsAction()
    {
        $extensions = array_merge(
            $this->getBundleList(),
            $this->getBrickList()
        );

        return $this->adminJson(['extensions' => $extensions]);
    }

    /**
     * Updates bundle options (priority, environments)
     *
     * @Route("/admin/extensions", name="pimcore_admin_extensionmanager_extensionmanager_updateextensions", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateExtensionsAction(Request $request)
    {
        $data = $this->decodeJson($request->getContent());

        if (!is_array($data) || !isset($data['extensions']) || !is_array($data['extensions'])) {
            throw new BadRequestHttpException('Invalid data. Need an array of extensions to update.');
        }

        $updates = [];
        foreach ($data['extensions'] as $row) {
            if (!$row || !is_array($row) || !isset($row['id'])) {
                throw new BadRequestHttpException('Invalid data. Missing row ID.');
            }

            $id = (string)$row['id'];

            $options = [];
            if (isset($row['environments'])) {
                $environments = explode(',', $row['environments']);
                $environments = array_map(function ($item) {
                    return trim((string)$item);
                }, $environments);

                $options['environments'] = $environments;
            }

            if (isset($row['priority'])) {
                $options['priority'] = (int)$row['priority'];
            }

            $updates[$id] = $options;
        }

        $this->getBundleManager()->setStates($updates);

        return $this->adminJson([
            'extensions' => $this->getBundleList(array_keys($updates)),
        ]);
    }

    /**
     * @Route("/admin/toggle-extension-state", name="pimcore_admin_extensionmanager_extensionmanager_toggleextensionstate", methods={"PUT"})
     *
     * @param Request $request
     * @param KernelInterface $kernel
     * @param AssetsInstaller $assetsInstaller
     *
     * @return JsonResponse
     */
    public function toggleExtensionStateAction(
        Request $request,
        KernelInterface $kernel,
        CacheClearer $cacheClearer,
        AssetsInstaller $assetsInstaller
    ) {
        $type = $request->get('type');
        $id = $request->get('id');
        $enable = $request->get('method', 'enable') === 'enable' ? true : false;

        $reload = false;
        $message = null;

        $data = [
            'success' => true,
            'errors' => [],
        ];

        if ($type === 'bundle') {
            $this->getBundleManager()->setState($id, ['enabled' => $enable]);
            $reload = true;

            $message = $this->installAssets($assetsInstaller, $enable);

            // clear the cache if kernel is not in debug mode (= auto-rebuilds container)
            if (!$kernel->isDebug()) {
                try {
                    $cacheClearer->clear($kernel->getEnvironment(), [
                        'no-warmup' => true,
                    ]);
                } catch (\Throwable $e) {
                    $data['errors'][] = $e->getMessage();
                }
            }
        } elseif ($type === 'areabrick') {
            $this->areabrickManager->setState($id, $enable);
            $reload = true;
        }

        $data['reload'] = $reload;

        if ($message) {
            $data['message'] = $message;
        }

        return $this->adminJson($data);
    }

    /**
     * Runs array:install command and returns its result as array (line-by-line)
     *
     * @param AssetsInstaller $assetsInstaller
     * @param bool $enable
     *
     * @return array
     */
    private function installAssets(AssetsInstaller $assetsInstaller, bool $enable): array
    {
        $message = null;

        try {
            $installProcess = $assetsInstaller->install();

            if ($enable) {
                $message = str_replace("'", '', $installProcess->getCommandLine()) . PHP_EOL . $installProcess->getOutput();
            }
        } catch (ProcessFailedException $e) {
            $message = 'Failed to run assets:install command. Please run command manually.' . PHP_EOL . PHP_EOL . $e->getMessage();
        }

        if (!$message) {
            return [];
        }

        $message = Encoding::fixUTF8($message);
        $message = (new AnsiToHtmlConverter())->convert($message);

        return explode(PHP_EOL, $message);
    }

    /**
     * @Route("/admin/install", name="pimcore_admin_extensionmanager_extensionmanager_install", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function installAction(Request $request)
    {
        return $this->handleInstallation($request, true);
    }

    /**
     * @Route("/admin/uninstall", name="pimcore_admin_extensionmanager_extensionmanager_uninstall", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function uninstallAction(Request $request)
    {
        return $this->handleInstallation($request, false);
    }

    private function handleInstallation(Request $request, bool $install = true): JsonResponse
    {
        try {
            $bundle = $this->getBundleManager()->getActiveBundle($request->get('id'), false);

            if ($install) {
                $this->getBundleManager()->install($bundle);
            } else {
                $this->getBundleManager()->uninstall($bundle);
            }

            $data = [
                'success' => true,
                'reload' => $this->getBundleManager()->needsReloadAfterInstall($bundle),
            ];

            if (!empty($message = $this->getInstallerOutput($bundle))) {
                $data['message'] = $message;
            }

            return $this->adminJson($data);
        } catch (BundleNotFoundException $e) {
            return $this->adminJson([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            return $this->adminJson([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    private function getBundleList(array $filter = []): array
    {
        $bm = $this->getBundleManager();

        $results = [];
        foreach ($bm->getEnabledBundleNames() as $className) {
            try {
                $bundle = $bm->getActiveBundle($className, false);

                $results[$bm->getBundleIdentifier($bundle)] = $this->buildBundleInfo($bundle, true, $bm->isInstalled($bundle));
            } catch (\Throwable $e) {
                Logger::error((string) $e);
            }
        }

        foreach ($bm->getAvailableBundles() as $className) {
            // bundle is enabled
            if (array_key_exists($className, $results)) {
                continue;
            }

            $bundle = $this->buildBundleInstance($className);
            if ($bundle) {
                $results[$bm->getBundleIdentifier($bundle)] = $this->buildBundleInfo($bundle);
            }
        }

        $results = array_values($results);

        if (count($filter) > 0) {
            $results = array_filter($results, function (array $item) use ($filter) {
                return in_array($item['id'], $filter);
            });
        }

        // show enabled/active first, then order by priority for
        // bundles with the same enabled state
        usort($results, function ($a, $b) {
            if ($a['active'] && !$b['active']) {
                return -1;
            }

            if (!$a['active'] && $b['active']) {
                return 1;
            }

            if ($a['active'] === $b['active']) {
                if ($a['priority'] === $b['priority']) {
                    return 0;
                }

                // reverse sorty by priority -> higher comes first
                return $a['priority'] < $b['priority'] ? 1 : -1;
            }
        });

        return $results;
    }

    private function buildBundleInstance(string $bundleName): ?PimcoreBundleInterface
    {
        try {
            /** @var PimcoreBundleInterface $bundle */
            $bundle = new $bundleName();
            $bundle->setContainer(\Pimcore::getContainer());

            return $bundle;
        } catch (\Exception $e) {
            Logger::error('Failed to build instance of bundle {bundle}: {error}', [
                'bundle' => $bundleName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function buildBundleInfo(PimcoreBundleInterface $bundle, bool $enabled = false, bool $installed = false): array
    {
        $bm = $this->getBundleManager();

        $state = $bm->getState($bundle);

        $info = [
            'id' => $bm->getBundleIdentifier($bundle),
            'type' => 'bundle',
            'name' => !empty($bundle->getNiceName()) ? $bundle->getNiceName() : $bundle->getName(),
            'active' => $enabled,
            'installable' => false,
            'uninstallable' => false,
            'installed' => $installed,
            'canChangeState' => $bm->canChangeState($bundle),
            'configuration' => $this->getIframePath($bundle),
            'version' => $bundle->getVersion(),
            'priority' => $state['priority'],
            'environments' => implode(', ', $state['environments']),
        ];

        // only check for installation specifics if the bundle is enabled
        if ($enabled) {
            $info = array_merge($info, [
                'installable' => $bm->canBeInstalled($bundle),
                'uninstallable' => $bm->canBeUninstalled($bundle),
            ]);
        }

        // get description as last item as it may contain installer output
        $description = $bundle->getDescription();
        if (!empty($installerOutput = $this->getInstallerOutput($bundle))) {
            if (!empty($description)) {
                $description = $description . '. ';
            }

            $description .= $installerOutput;
        }

        $info['description'] = $description;

        return $info;
    }

    private function getIframePath(PimcoreBundleInterface $bundle): ?string
    {
        if ($iframePath = $bundle->getAdminIframePath()) {
            if ($iframePath instanceof RouteReferenceInterface) {
                return $this->generateUrl(
                    $iframePath->getRoute(),
                    $iframePath->getParameters(),
                    $iframePath->getType()
                );
            }

            return $iframePath;
        }

        return null;
    }

    private function getBrickList(): array
    {
        $results = [];
        foreach ($this->areabrickManager->getBricks() as $brick) {
            $results[] = $this->buildBrickInfo($brick);
        }

        return $results;
    }

    private function buildBrickInfo(AreabrickInterface $brick): array
    {
        return [
            'id' => $brick->getId(),
            'type' => 'areabrick',
            'name' => $this->trans($brick->getName()),
            'description' => $this->trans($brick->getDescription()),
            'installable' => false,
            'uninstallable' => false,
            'installed' => true,
            'active' => $this->areabrickManager->isEnabled($brick->getId()),
            'version' => $brick->getVersion(),
        ];
    }

    private function getInstallerOutput(PimcoreBundleInterface $bundle, bool $decorated = false): ?string
    {
        if (!$this->getBundleManager()->isEnabled($bundle)) {
            return null;
        }

        $installer = $this->getBundleManager()->getInstaller($bundle);
        if (null !== $installer) {
            $output = $installer->getOutput();
            if ($output instanceof BufferedOutput) {
                $converter = new AnsiToHtmlConverter(null);

                $converted = Encoding::fixUTF8($output->fetch());
                $converted = $converter->convert($converted);

                if (!$decorated) {
                    $converted = strip_tags($converted);
                }

                return $converted;
            }
        }

        return null;
    }
}
