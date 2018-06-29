<?php

namespace Pantheon\Terminus\Commands\HTTPS;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Class VerifyACMEChallengeCommand
 * @package Pantheon\Terminus\Commands\HTTPS
 */
class ChallengeCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * Writes a challenge file to the current directory (or the specified
     * location) and prints instructions on how to serve it.
     *
     * @authorize
     *
     * @command https:challenge:file
     * @aliases acme-file
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain The domain to produce a challenge for.
     *
     * @usage <site>.<env> Displays domains associated with <site>'s <env> environment.
     */
    public function writeChallengeFile($site_env, $domain)
    {
        list($data, $acmeStatus) = $this->getACMEStatus($site_env, $domain);
        if (!$acmeStatus) {
            return;
        }

        // Sanity check: this should never happen, as getACMEStatus should throw
        // in any instance where there is no verification file data.
        if (empty($data->verification_file_name) || empty($data->verification_file_link)) {
            throw new TerminusException('No challenge file information available for domain {domain}.', compact('status', 'domain'));
        }

        $filename = $data->verification_file_name;
        $contents = file_get_contents($data->verification_file_link);

        file_put_contents($filename, $contents);
        $this->log()->notice('Wrote ACME challenge to file {filename}', compact('filename'));
        $this->log()->notice('Please copy this file to your web server so that it will be served from the URL');
        $this->log()->notice('{url}', ['url' => "http://$domain/.well-known/acme-challenge/$filename"]);
    }

    /**
     * Get a DNS-txt record challenge.
     *
     * @authorize
     *
     * @command https:challenge:dns-txt
     * @aliases acme-txt
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain The domain to produce a challenge for.
     *
     * @usage <site>.<env> Displays domains associated with <site>'s <env> environment.
     */
    public function getChallengeDNStxt($site_env, $domain)
    {
        list($data, $acmeStatus) = $this->getACMEStatus($site_env, $domain);
        if (!$acmeStatus) {
            return;
        }

        // Sanity check: this should never happen, as getACMEStatus should throw
        // in any instance where there is no verification dns txt record.
        if (empty($data->verification_dns_txt)) {
            throw new TerminusException('No DNS txt record challenge information available for domain {domain}.', compact('status', 'domain'));
        }

        $contents = $data->verification_dns_txt;
        $this->log()->notice('Create a DNS txt record:', compact('contents', 'domain'));
        $this->log()->notice('_acme-challenge.{domain}. 300 IN TXT "{contents}"', compact('contents', 'domain'));
    }

    /**
     * Look up the HTTPS ACME verification status for a site & environment
     * that need verification.
     */
    protected function getACMEStatus($site_env, $domain)
    {
        list(, $env) = $this->getSiteEnv($site_env);

        $domains = $env->getDomains()->fetchWithRecommendations();
        if (!$domains->has($domain)) {
            $command = "terminus domain:add $site_env $domain";
            $this->log()->notice('The domain {domain} has not been added to this site and environment. Use the command {command} to add it.', compact('domain', 'command'));
            throw new TerminusException('Cannot create challenge file.');
        }
        $domainToVerify = $domains->get($domain);

        $data = $domains->getACMEStatus($domainToVerify->id);
        $data = $data->ownership_status;
        $status = $data->status;

        if ($status == 'completed') {
            $this->log()->notice('Domain verification for {domain} has been completed.', compact('domain'));
            return [$data, false];
        }

        if ($status == 'not_required') {
            $this->log()->notice('Domain verification for {domain} is not necessary; https has not been configured for this domain in its current location.', compact('domain'));
            return [$data, false];
        }

        if ($status != 'required') {
            throw new TerminusException('Unimplemented status {status} for domain {domain}.', compact('status', 'domain'));
        }

        return [$data, true];
    }
}
